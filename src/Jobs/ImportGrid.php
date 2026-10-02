<?php

namespace CodeCorner\Datagrid\Jobs;

use CodeCorner\Datagrid\Auth\AuthManager;
use CodeCorner\Datagrid\Core\CellUpdater;
use CodeCorner\Datagrid\Core\Column;
use CodeCorner\Datagrid\Core\Grid;
use CodeCorner\Datagrid\Core\GridRegistry;
use CodeCorner\Datagrid\Exceptions\InvalidGridRequest;
use CodeCorner\Datagrid\Support\JobProgress;
use CodeCorner\Datagrid\Support\ResultCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ImportGrid implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $gridKey,
        public string $path,
        public mixed $actor,
        public string $jobId,
        /** @var array<string, string> csv header => grid field */
        public array $mapping,
    ) {}

    public function handle(GridRegistry $registry, AuthManager $auth, CellUpdater $updater): void
    {
        JobProgress::update($this->jobId, ['status' => 'running', 'done' => 0, 'total' => 0]);

        try {
            $grid = $registry->find($this->gridKey);
            if ($grid === null) {
                throw new \RuntimeException('This grid is no longer available.');
            }

            $parsed = $this->parse();
            $columns = $this->columns($grid, $auth);
            $total = count($parsed['rows']);
            JobProgress::update($this->jobId, ['total' => $total]);

            $updated = 0;
            $errors = [];
            $key = $grid->primaryKey();

            foreach ($parsed['rows'] as $index => $row) {
                $line = $index + 2;
                $mapped = [];
                foreach ($this->mapping as $header => $field) {
                    $mapped[$field] = $row[$header] ?? '';
                }
                $id = $mapped[$key] ?? null;
                if ($id === null || $id === '') {
                    $errors[] = "Row {$line}: missing {$key}.";
                    JobProgress::update($this->jobId, ['done' => $index + 1]);

                    continue;
                }

                $model = $grid->scopedQuery($this->actor)->whereKey($id)->first();
                if ($model === null) {
                    $errors[] = "Row {$line}: row {$id} is outside this grid.";
                    JobProgress::update($this->jobId, ['done' => $index + 1]);

                    continue;
                }

                try {
                    foreach ($columns as $column) {
                        if (! array_key_exists($column->key(), $mapped)) {
                            continue;
                        }
                        $updater->assign($column, $model, $mapped[$column->key()] === '' ? null : $mapped[$column->key()]);
                    }
                    $model->save();
                    $updated++;
                } catch (InvalidGridRequest $e) {
                    $errors[] = "Row {$line}: {$e->getMessage()}";
                }

                JobProgress::update($this->jobId, ['done' => $index + 1, 'total' => $total]);
            }

            $message = 'Updated '.$updated.' '.($updated === 1 ? 'row' : 'rows').'.';
            if ($errors !== []) {
                $message .= ' '.count($errors).' failed. '.implode(' ', array_slice($errors, 0, 5));
            }

            JobProgress::update($this->jobId, [
                'status' => $updated === 0 && $errors !== [] ? 'failed' : 'done',
                'done' => $total,
                'total' => $total,
                'message' => $message,
            ]);
            if ($updated > 0) {
                ResultCache::bump($this->gridKey);
            }
        } catch (Throwable $e) {
            JobProgress::update($this->jobId, [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ]);
        } finally {
            if (is_file($this->path)) {
                unlink($this->path);
            }
        }
    }

    /**
     * @return array{header: list<string>, rows: list<array<string, string>>}
     */
    private function parse(): array
    {
        $sample = file_get_contents($this->path, false, null, 0, 8192);
        if (! is_string($sample) || str_contains($sample, "\0") || preg_match('/<\?(php|=)/i', $sample)) {
            throw new \RuntimeException('The import file must be a plain CSV.');
        }

        $handle = fopen($this->path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Could not read the import file.');
        }

        $header = fgetcsv($handle);
        if (! is_array($header) || $header === [null] || $header === []) {
            fclose($handle);
            throw new \RuntimeException('The import file has no header row.');
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? (string) $header[0];
        $header = array_map(fn ($name) => trim((string) $name), $header);

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $name) {
                if ($name === '') {
                    continue;
                }
                $row[$name] = isset($line[$i]) ? trim((string) $line[$i]) : '';
            }
            $rows[] = $row;
            if (count($rows) > 5000) {
                fclose($handle);
                throw new \RuntimeException('Import files are limited to 5000 rows.');
            }
        }
        fclose($handle);

        return ['header' => $header, 'rows' => $rows];
    }

    /** @return list<Column> */
    private function columns(Grid $grid, AuthManager $auth): array
    {
        $allowed = $grid->allowedColumns($this->actor, $auth);
        $columns = [];
        foreach (array_unique(array_values($this->mapping)) as $key) {
            if ($key === $grid->primaryKey()) {
                continue;
            }
            $column = $allowed[$key] ?? null;
            if ($column instanceof Column && $column->isImportable() && $column->kind() === 'direct' && ! $column->isHidden() && $grid->canEditColumn($column, $this->actor, $auth)) {
                $columns[] = $column;
            }
        }

        if ($columns === []) {
            throw new \RuntimeException('The import file has no importable columns.');
        }

        return $columns;
    }
}
