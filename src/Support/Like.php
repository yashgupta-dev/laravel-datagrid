<?php

namespace CodeCorner\Datagrid\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

/**
 * Portable, injection-safe LIKE. Uses "!" as the escape character because a
 * backslash escape behaves differently across MySQL, PostgreSQL and SQLite.
 */
final class Like
{
    public static function pattern(string $value, string $mode = 'contains'): string
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);

        return match ($mode) {
            'starts' => $escaped.'%',
            'ends' => '%'.$escaped,
            default => '%'.$escaped.'%',
        };
    }

    public static function apply(
        Builder $query,
        string|Expression $expr,
        string $pattern,
        string $boolean = 'and',
        bool $negate = false,
    ): void {
        $base = $query->getQuery();
        $grammar = $base->getGrammar();
        $column = $expr instanceof Expression ? $expr->getValue($grammar) : $grammar->wrap($expr);
        $pgsql = $base->getConnection()->getDriverName() === 'pgsql';

        if ($pgsql) {
            $column = "cast({$column} as text)";
        }

        $sql = $column.($negate ? ' not' : '').' '.($pgsql ? 'ilike' : 'like')." ? escape '!'";
        $query->whereRaw($sql, [$pattern], $boolean);
    }
}
