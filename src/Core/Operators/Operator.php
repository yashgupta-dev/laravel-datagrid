<?php

namespace CodeCorner\Datagrid\Core\Operators;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

interface Operator
{
    public function name(): string;

    /**
     * Apply the condition. $expr is a qualified column or a developer-defined raw
     * expression, never user input. Throw InvalidFilterException on a bad value.
     */
    public function apply(Builder $query, string|Expression $expr, mixed $value, string $boolean = 'and'): void;
}
