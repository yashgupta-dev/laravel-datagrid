<?php

namespace CodeCorner\Datagrid\Tests\Fixtures;

use CodeCorner\Datagrid\Contracts\ScopesQuery;
use Illuminate\Database\Eloquent\Builder;

require_once __DIR__.'/UserGrid.php';

class ScopedUserGrid extends UserGrid implements ScopesQuery
{
    public function scope(Builder $query, mixed $actor): Builder
    {
        return $query->where('name', 'Alice');
    }
}
