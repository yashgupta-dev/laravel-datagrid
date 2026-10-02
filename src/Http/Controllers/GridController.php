<?php

namespace CodeCorner\Datagrid\Http\Controllers;

use Closure;
use CodeCorner\Datagrid\Auth\ActorResolver;
use CodeCorner\Datagrid\Auth\AuthContext;
use CodeCorner\Datagrid\Auth\AuthManager;
use CodeCorner\Datagrid\Core\ActionRunner;
use CodeCorner\Datagrid\Core\CellUpdater;
use CodeCorner\Datagrid\Core\Column;
use CodeCorner\Datagrid\Core\Grid;
use CodeCorner\Datagrid\Core\GridRegistry;
use CodeCorner\Datagrid\Core\Operators\OperatorRegistry;
use CodeCorner\Datagrid\Core\Query\DataRequest;
use CodeCorner\Datagrid\Core\Query\QueryEngine;
use CodeCorner\Datagrid\Exceptions\InvalidGridRequest;
use CodeCorner\Datagrid\Jobs\ExportGrid;
use CodeCorner\Datagrid\Jobs\ImportGrid;
use CodeCorner\Datagrid\Jobs\RunGridAction;
use CodeCorner\Datagrid\Models\SavedFilter;
use CodeCorner\Datagrid\Support\JobProgress;
use CodeCorner\Datagrid\Support\ResultCache;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GridController extends Controller
{
    public function __construct(
        private GridRegistry $registry,
        private AuthManager $auth,
        private ActorResolver $actors,
        private OperatorRegistry $operators,
        private QueryEngine $engine,
        private ActionRunner $runner,
        private CellUpdater $cells,
    ) {}

    public function schema(Request $request, string $grid): JsonResponse
    {
        [$g, $actor] = $this->open($request, $grid);

        $schema = ResultCache::remember($grid, $this->actorKey($actor), ['schema' => 7], fn () => $g->schema(
            $actor,
            $this->auth,
            $this->operators,
            config('datagrid.limits', [])
        ), 'schema');

        return response()->json($schema);
    }

    public function data(Request $request, string $grid): JsonResponse
    {
        [$g, $actor] = $this->open($request, $grid);

        $validated = $this->validateData($request);

        try {
            $result = ResultCache::using($grid, fn () => ResultCache::remember($grid, $this->actorKey($actor), $validated, function () use ($g, $actor, $validated) {
                $dataRequest = DataRequest::fromArray($validated, config('datagrid.limits', []));

                return $this->engine->run($g, $g->allowedColumns($actor, $this->auth), $actor, $dataRequest);
            }));
        } catch (InvalidGridRequest $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function action(Request $request, string $grid, string $action): JsonResponse
    {
        [$g, $actor] = $this->open($request, $grid);

        $found = $g->findAction($action) ?? abort(404);
        abort_unless($this->auth->allows(
            $actor,
            $found->getAbility(),
            new AuthContext($g->key(), AuthContext::ACTION, $found->key()),
            $g->authorizer()
        ), 403);

        $max = (int) config('datagrid.limits.max_action_ids', 500);
        $validated = $request->validate([
            'ids' => "required|array|min:1|max:{$max}",
            'ids.*' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_scalar($value)) {
                    $fail('Each id must be a scalar.');
                }
            }],
            'bulk' => 'sometimes|boolean',
        ]);

        $ids = array_values($validated['ids']);
        if ($request->boolean('bulk')) {
            abort_unless($found->isBulk(), 422);

            return $this->queue($grid, $actor, function (string $jobId, string $actorKey, string $token) use ($grid, $found, $ids, $actor) {
                JobProgress::start($jobId, $actorKey, count($ids), $this->jobMeta($grid, $token));
                Bus::dispatch(new RunGridAction($grid, $found->key(), $ids, $actor, $jobId));
            });
        }

        try {
            $result = $this->runner->run($g, $found, $actor, $ids);
        } catch (InvalidGridRequest $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ResultCache::bump($grid);

        return response()->json($result);
    }

    public function export(Request $request, string $grid): JsonResponse
    {
        [$g, $actor] = $this->open($request, $grid);
        abort_unless($g->exportable(), 403);
        $this->allow($actor, $g, AuthContext::EXPORT);
        $validated = $this->validateData($request);

        return $this->queue($grid, $actor, function (string $jobId, string $actorKey, string $token) use ($grid, $actor, $validated) {
            JobProgress::start($jobId, $actorKey, 0, $this->jobMeta($grid, $token));
            Bus::dispatch(new ExportGrid($grid, $validated, $actor, $jobId));
        });
    }

    public function import(Request $request, string $grid): JsonResponse
    {
        [$g, $actor] = $this->open($request, $grid);
        abort_unless($g->importable(), 403);
        $this->allow($actor, $g, AuthContext::IMPORT);
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
            'mapping' => 'required',
        ]);
        $mapping = $this->importMapping($request, $g, $actor);
        $sample = file_get_contents($request->file('file')->getRealPath(), false, null, 0, 8192);
        if (! is_string($sample) || str_contains($sample, "\0") || preg_match('/<\?(php|=)/i', $sample)) {
            return response()->json(['message' => 'The import file must be a plain CSV.'], 422);
        }

        $dir = storage_path('app/datagrid-imports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $this->queue($grid, $actor, function (string $jobId, string $actorKey, string $token) use ($request, $grid, $actor, $dir, $mapping) {
            $path = $dir.'/'.$jobId.'.csv';
            $request->file('file')->move($dir, $jobId.'.csv');
            JobProgress::start($jobId, $actorKey, 0, $this->jobMeta($grid, $token));
            Bus::dispatch(new ImportGrid($grid, $path, $actor, $jobId, $mapping));
        });
    }

    public function job(Request $request, string $grid, string $job): JsonResponse
    {
        [, $actor] = $this->open($request, $grid);
        $row = $this->ownedJob($request, $grid, $actor, $job);

        return response()->json(JobProgress::public($row));
    }

    public function download(Request $request, string $grid, string $job): BinaryFileResponse
    {
        [, $actor] = $this->open($request, $grid);
        $row = $this->ownedJob($request, $grid, $actor, $job);
        $file = $row['file'] ?? null;
        $root = realpath(storage_path('app/datagrid-exports'));
        $real = is_string($file) ? realpath($file) : false;
        abort_unless(is_string($root) && is_string($real) && str_starts_with($real, $root.DIRECTORY_SEPARATOR), 404);
        $name = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $grid), '-') ?: 'grid';

        return response()->download($real, $name.'-export.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function updateCell(Request $request, string $grid, string $id): JsonResponse
    {
        [$g, $actor] = $this->open($request, $grid);
        $validated = $request->validate([
            'field' => 'required|string',
            'value' => 'nullable',
        ]);

        $column = $g->allowedColumns($actor, $this->auth)[$validated['field']] ?? null;
        if (! $column instanceof Column || ! $column->isEditable() || ! $g->canEditColumn($column, $actor, $this->auth)) {
            return response()->json(['message' => 'Field ['.$validated['field'].'] cannot be edited.'], 422);
        }

        $model = $g->scopedQuery($actor)->whereKey($id)->first();
        if ($model === null) {
            abort(404);
        }

        try {
            $this->cells->assign($column, $model, $validated['value']);
            $model->save();
        } catch (InvalidGridRequest $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ResultCache::bump($grid);

        return response()->json([
            'id' => $model->getKey(),
            'field' => $column->key(),
            'value' => $model->getAttribute($column->attributeName()),
        ]);
    }

    public function filters(Request $request, string $grid): JsonResponse
    {
        [$g, $actor] = $this->open($request, $grid);
        $actorId = $this->actorKey($actor);
        if ($actorId === '') {
            return response()->json(['data' => [], 'persisted' => false]);
        }

        $rows = SavedFilter::query()
            ->where('actor_id', $actorId)
            ->where('grid', $g->key())
            ->orderBy('name')
            ->get()
            ->map(fn (SavedFilter $filter) => $this->filterPayload($filter));

        return response()->json(['data' => $rows, 'persisted' => true]);
    }

    public function storeFilter(Request $request, string $grid): JsonResponse
    {
        [$g, $actorId] = $this->ownedFilters($request, $grid);
        $validated = $this->validateFilter($request, true);

        try {
            $filter = SavedFilter::query()->create([
                'actor_id' => $actorId,
                'grid' => $g->key(),
                'name' => $validated['name'],
                'payload' => [
                    'filters' => $validated['filters'],
                    'search' => $validated['search'] ?? '',
                ],
            ]);
        } catch (QueryException) {
            return response()->json(['message' => 'A saved filter with that name already exists.'], 422);
        }

        return response()->json($this->filterPayload($filter), 201);
    }

    public function updateFilter(Request $request, string $grid, string $filter): JsonResponse
    {
        [$g, $actorId] = $this->ownedFilters($request, $grid);
        $saved = $this->findFilter($g, $actorId, $filter);
        $validated = $this->validateFilter($request, false);
        $payload = $saved->payload ?? ['filters' => [], 'search' => ''];

        if (array_key_exists('name', $validated)) {
            $saved->name = $validated['name'];
        }
        if (array_key_exists('filters', $validated)) {
            $payload['filters'] = $validated['filters'];
        }
        if (array_key_exists('search', $validated)) {
            $payload['search'] = $validated['search'] ?? '';
        }
        $saved->payload = $payload;

        try {
            $saved->save();
        } catch (QueryException) {
            return response()->json(['message' => 'A saved filter with that name already exists.'], 422);
        }

        return response()->json($this->filterPayload($saved));
    }

    public function destroyFilter(Request $request, string $grid, string $filter): JsonResponse
    {
        [$g, $actorId] = $this->ownedFilters($request, $grid);
        $this->findFilter($g, $actorId, $filter)->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1',
            'sort' => 'sometimes|array',
            'sort.*.field' => 'required_with:sort|string',
            'sort.*.dir' => 'sometimes|in:asc,desc',
            'filter' => 'sometimes|nullable|array',
            'search' => 'sometimes|nullable|string',
            'columns' => 'sometimes|array',
            'columns.*' => 'string',
            'includeTotal' => 'sometimes|boolean',
        ]);
    }

    /** @return array<string, mixed> */
    private function validateFilter(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:80'],
            'filters' => [$creating ? 'present' : 'sometimes', 'array'],
            'filters.*.field' => 'required|string',
            'filters.*.op' => 'required|string',
            'filters.*.value' => 'nullable',
            'search' => 'sometimes|nullable|string|max:100',
        ]);
    }

    /** @return array{0: Grid, 1: mixed} */
    private function open(Request $request, string $key): array
    {
        $grid = $this->registry->find($key) ?? abort(404);
        $actor = $this->actors->resolve($request);

        abort_unless($grid->canOpen($actor, $this->auth), 403);

        return [$grid, $actor];
    }

    private function allow(mixed $actor, Grid $grid, string $scope): void
    {
        abort_unless($this->auth->allows(
            $actor,
            null,
            new AuthContext($grid->key(), $scope),
            $grid->authorizer()
        ), 403);
    }

    /** @return array{0: Grid, 1: string} */
    private function ownedFilters(Request $request, string $grid): array
    {
        [$g, $actor] = $this->open($request, $grid);
        $actorId = $this->actorKey($actor);
        abort_if($actorId === '', 401);

        return [$g, $actorId];
    }

    private function findFilter(Grid $grid, string $actorId, string $id): SavedFilter
    {
        return SavedFilter::query()
            ->where('actor_id', $actorId)
            ->where('grid', $grid->key())
            ->whereKey($id)
            ->first() ?? abort(404);
    }

    /** @return array{id: int|string, name: string, filters: array<int, mixed>, search: string} */
    private function filterPayload(SavedFilter $filter): array
    {
        $payload = $filter->payload ?? [];

        return [
            'id' => $filter->getKey(),
            'name' => $filter->name,
            'filters' => $payload['filters'] ?? [],
            'search' => $payload['search'] ?? '',
        ];
    }

    /** @return array<string, mixed> */
    private function ownedJob(Request $request, string $grid, mixed $actor, string $id): array
    {
        $row = JobProgress::get($id);
        $given = hash('sha256', (string) $request->header('X-Datagrid-Token', ''));
        $known = (string) ($row['token'] ?? '');
        abort_unless(
            is_array($row)
            && ($row['grid'] ?? '') === $grid
            && ($row['actor'] ?? null) === $this->actorKey($actor)
            && $known !== ''
            && hash_equals($known, $given),
            404
        );

        return $row;
    }

    /** @return array{grid: string, token: string} */
    private function jobMeta(string $grid, string $token): array
    {
        return ['grid' => $grid, 'token' => hash('sha256', $token)];
    }

    /**
     * @return array<string, string>
     */
    private function importMapping(Request $request, Grid $grid, mixed $actor): array
    {
        $raw = $request->input('mapping');
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            abort(response()->json(['message' => 'Column mapping is required.'], 422));
        }

        $allowed = $grid->allowedColumns($actor, $this->auth);
        $primary = $grid->primaryKey();
        $clean = [];
        $used = [];
        foreach ($raw as $header => $field) {
            if (! is_string($header) || $header === '' || strlen($header) > 200) {
                abort(response()->json(['message' => 'A mapped column name is invalid.'], 422));
            }
            if ($field === null || $field === '') {
                continue;
            }
            if (! is_string($field)) {
                abort(response()->json(['message' => 'A mapped field is invalid.'], 422));
            }
            if (isset($used[$field])) {
                abort(response()->json(['message' => "Field [{$field}] is mapped more than once."], 422));
            }
            if ($field !== $primary) {
                $column = $allowed[$field] ?? null;
                if (! $column instanceof Column || ! $column->isImportable() || $column->kind() !== 'direct' || $column->isHidden() || ! $grid->canEditColumn($column, $actor, $this->auth)) {
                    abort(response()->json(['message' => "Field [{$field}] cannot be imported."], 422));
                }
            }
            $used[$field] = true;
            $clean[$header] = $field;
        }

        if (! in_array($primary, $clean, true)) {
            abort(response()->json(['message' => "Map a column to the primary key [{$primary}]."], 422));
        }
        if (count($clean) < 2) {
            abort(response()->json(['message' => 'Map at least one importable column.'], 422));
        }

        return $clean;
    }

    private function queue(string $grid, mixed $actor, Closure $dispatch): JsonResponse
    {
        $jobId = (string) Str::uuid();
        $token = Str::random(48);
        $dispatch($jobId, $this->actorKey($actor), $token);

        return response()->json(['job' => $jobId, 'token' => $token, 'status' => 'queued']);
    }

    private function actorKey(mixed $actor): string
    {
        if (is_object($actor) && method_exists($actor, 'getAuthIdentifier')) {
            $id = $actor->getAuthIdentifier();

            return $id === null ? '' : (string) $id;
        }

        return is_scalar($actor) ? (string) $actor : '';
    }
}
