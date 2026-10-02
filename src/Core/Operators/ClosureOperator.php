<?php

namespace CodeCorner\Datagrid\Core\Operators;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

class ClosureOperator implements Operator
{
    public function __construct(private string $name, private Closure $apply) {}

    public function name(): string
    {
        return $this->name;
    }

    public function apply(Builder $query, string|Expression $expr, mixed $value, string $boolean = 'and'): void
    {
        ($this->apply)($query, $expr, $value, $boolean);
    }
}
