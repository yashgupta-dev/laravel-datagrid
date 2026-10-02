<?php

namespace CodeCorner\Datagrid\Core\Query;

use CodeCorner\Datagrid\Core\Column;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Per-run state: the query plus lazily registered joins and aggregates. */
final class QueryContext
{
    public readonly Model $model;

    public readonly string $table;

    /** @var array<string, string> relation name => join alias */
    public array $joins = [];

    /** @var array<string, true> */
    public array $aggregates = [];

    /** @param array<string, Column> $columns */
    public function __construct(public readonly Builder $query, public readonly array $columns)
    {
        $this->model = $query->getModel();
        $this->table = $this->model->getTable();
    }
}
