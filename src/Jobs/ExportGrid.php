<?php

namespace CodeCorner\Datagrid\Jobs;

use CodeCorner\Datagrid\Auth\AuthManager;
use CodeCorner\Datagrid\Core\Column;
use CodeCorner\Datagrid\Core\Grid;
use CodeCorner\Datagrid\Core\GridRegistry;
use CodeCorner\Datagrid\Core\Query\DataRequest;
use CodeCorner\Datagrid\Core\Query\QueryEngine;
use CodeCorner\Datagrid\Support\JobProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExportGrid implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $gridKey,
        public array $payload,
        public mixed $actor,
        public string $jobId,
    ) {}

    public function handle(GridRegistry $registry, AuthManager $auth, QueryEngine $engine): void
    {
        JobProgress::update($this->jobId, ['status' => 'running']);

        try {
            $grid = $registry->find($this->gridKey);
            if ($grid === null) {
                throw new \RuntimeException('This grid is no longer available.');
            }

            $columns = $this->exportColumns($grid, $auth);
            if ($columns === []) {
                throw new \RuntimeException('No exportable columns are selected.');
            }

            $dir = storage_path('app/datagrid-exports');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $path = $dir.'/'.$this->jobId.'.csv';
            $handle = fopen($path, 'w');
            if ($handle === false) {
                throw new \RuntimeException('Could not create the export file.');
            }

            fputcsv($handle, array_map(fn (Column $column) => $column->key(), $columns));

            $page = 1;
            $done = 0;
            $total = 0;
            $limits = config('datagrid.limits', []);
            $allowed = $grid->allowedColumns($this->actor, $auth);

            do {
                $request = DataRequest::fromArray(array_merge($this->payload, [
                    'page' => $page,
                    'perPage' => 200,
                    'includeTotal' => true,
                    'columns' => array_map(fn (Column $column) => $column->key(), $columns),
                ]), $limits);
                $result = $engine->run($grid, $allowed, $this->actor, $request);
                $total = (int) ($result['meta']['total'] ?? $done);
                $models = $this->modelsFor($grid, $result['data']);

                foreach ($result['data'] as $row) {
                    $model = $models[(string) $row['id']] ?? null;
                    $line = [];
                    foreach ($columns as $column) {
                        $callback = $column->exportCallback();
                        $value = $callback && $model ? $callback($model) : ($row['values'][$column->key()] ?? null);
                        $line[] = $this->cell($value);
                    }
                    fputcsv($handle, $line);
                    $done++;
                }

                JobProgress::update($this->jobId, ['done' => $done, 'total' => max($total, $done)]);
                $page++;
                $hasMore = (bool) ($result['meta']['hasMore'] ?? false);
            } while ($hasMore && $done < 10000);

            fclose($handle);

            JobProgress::update($this->jobId, [
                'status' => 'done',
                'done' => $done,
                'total' => max($total, $done),
                'message' => 'Exported '.$done.' '.($done === 1 ? 'row' : 'rows').'.',
                'file' => $path,
            ]);
        } catch (Throwable $e) {
            JobProgress::update($this->jobId, [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** @return list<Column> */
    private function exportColumns(Grid $grid, AuthManager $auth): array
    {
        $allowed = $grid->allowedColumns($this->actor, $auth);
        $wanted = array_values(array_filter((array) ($this->payload['columns'] ?? []), 'is_string'));
        $keys = $wanted !== [] ? $wanted : array_keys(array_filter(
            $allowed,
            fn (Column $column) => $column->isExportable() && $column->isVisibleByDefault() && ! $column->isHidden()
        ));

        $columns = [];
        foreach ($keys as $key) {
            $column = $allowed[$key] ?? null;
            if ($column instanceof Column && $column->isExportable() && ! $column->isHidden()) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @param  list<array{id: mixed, values: array<string, mixed>}>  $rows
     * @return array<string, Model>
     */
    private function modelsFor(Grid $grid, array $rows): array
    {
        $ids = array_column($rows, 'id');
        if ($ids === []) {
            return [];
        }

        return $grid->scopedQuery($this->actor)
            ->whereKey($ids)
            ->get()
            ->keyBy(fn (Model $model) => (string) $model->getKey())
            ->all();
    }

    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $text = is_scalar($value) ? (string) $value : (json_encode($value) ?: '');

        return self::plain($text);
    }

    /** Stop spreadsheet apps from treating a cell as a formula. */
    public static function plain(string $text): string
    {
        if ($text !== '' && preg_match('/^[\t\r=+@-]/', $text) === 1) {
            return "'".$text;
        }

        return $text;
    }
}
