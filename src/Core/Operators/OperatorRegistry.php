<?php

namespace CodeCorner\Datagrid\Core\Operators;

use CodeCorner\Datagrid\Exceptions\InvalidFilterException;
use CodeCorner\Datagrid\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

class OperatorRegistry
{
    /** @var array<string, Operator> */
    private array $operators = [];

    /** @var array<string, list<string>> */
    private array $byFilterType = [
        'text' => ['contains', 'not_contains', 'eq', 'neq', 'starts_with', 'ends_with', 'is_null', 'not_null'],
        'number' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'is_null', 'not_null'],
        'date' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'is_null', 'not_null'],
        'boolean' => ['eq'],
        'select' => ['in', 'not_in', 'eq', 'neq', 'is_null', 'not_null'],
        'relation' => ['in', 'not_in', 'eq', 'is_null', 'not_null'],
    ];

    public function __construct(private int $maxInValues = 500)
    {
        $this->registerDefaults();
    }

    public function register(Operator $operator): static
    {
        $this->operators[$operator->name()] = $operator;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->operators[$name]);
    }

    public function get(string $name): Operator
    {
        return $this->operators[$name] ?? throw new InvalidFilterException("Unknown operator [{$name}].");
    }

    /** @param list<string> $operators */
    public function registerFilterType(string $type, array $operators): static
    {
        $this->byFilterType[$type] = $operators;

        return $this;
    }

    /** @return list<string> */
    public function forFilterType(string $type): array
    {
        return $this->byFilterType[$type] ?? $this->byFilterType['text'];
    }

    private function registerDefaults(): void
    {
        $scalar = static function (mixed $v, string $op): mixed {
            if (is_array($v) || is_object($v) || $v === null) {
                throw new InvalidFilterException("Operator [{$op}] expects a single value.");
            }

            return $v;
        };

        $list = function (mixed $v, string $op): array {
            if (! is_array($v) || $v === []) {
                throw new InvalidFilterException("Operator [{$op}] expects a non-empty list.");
            }
            if (count($v) > $this->maxInValues) {
                throw new InvalidFilterException("Operator [{$op}] accepts at most {$this->maxInValues} values.");
            }
            foreach ($v as $item) {
                if (is_array($item) || is_object($item)) {
                    throw new InvalidFilterException("Operator [{$op}] expects a flat list.");
                }
            }

            return array_values($v);
        };

        $cmp = fn (string $name, string $sql) => $this->register(new ClosureOperator(
            $name,
            fn (Builder $q, string|Expression $e, mixed $v, string $b) => $q->where($e, $sql, $scalar($v, $name), $b),
        ));

        $cmp('eq', '=');
        $cmp('neq', '<>');
        $cmp('gt', '>');
        $cmp('gte', '>=');
        $cmp('lt', '<');
        $cmp('lte', '<=');

        $like = fn (string $name, string $mode, bool $negate = false) => $this->register(new ClosureOperator(
            $name,
            fn (Builder $q, string|Expression $e, mixed $v, string $b) => Like::apply(
                $q, $e, Like::pattern((string) $scalar($v, $name), $mode), $b, $negate
            ),
        ));

        $like('contains', 'contains');
        $like('not_contains', 'contains', true);
        $like('starts_with', 'starts');
        $like('ends_with', 'ends');

        $this->register(new ClosureOperator('between', function (Builder $q, string|Expression $e, mixed $v, string $b) {
            if (! is_array($v) || count($v) !== 2 || is_array($v[0] ?? null) || is_array($v[1] ?? null)) {
                throw new InvalidFilterException('Operator [between] expects [from, to].');
            }
            $q->whereBetween($e, [array_values($v)[0], array_values($v)[1]], $b);
        }));

        $this->register(new ClosureOperator('in', fn (Builder $q, string|Expression $e, mixed $v, string $b) => $q->whereIn($e, $list($v, 'in'), $b)));
        $this->register(new ClosureOperator('not_in', fn (Builder $q, string|Expression $e, mixed $v, string $b) => $q->whereNotIn($e, $list($v, 'not_in'), $b)));
        $this->register(new ClosureOperator('is_null', fn (Builder $q, string|Expression $e, mixed $v, string $b) => $q->whereNull($e, $b)));
        $this->register(new ClosureOperator('not_null', fn (Builder $q, string|Expression $e, mixed $v, string $b) => $q->whereNotNull($e, $b)));
    }
}
