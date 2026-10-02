<?php

namespace CodeCorner\Datagrid\Contracts;

use Illuminate\Database\Eloquent\Builder;

/**
 * Row-level access. Implement on a Grid to restrict which rows exist for an actor
 * (tenancy, ownership, departments). Applied to data, count, export and bulk-scope
 * queries, independent of the Authorizer driver.
 */
interface ScopesQuery
{
    public function scope(Builder $query, mixed $actor): Builder;
}
