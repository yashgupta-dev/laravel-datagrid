<?php

namespace CodeCorner\Datagrid\Core\Query;

use BackedEnum;
use CodeCorner\Datagrid\Contracts\ScopesQuery;
use CodeCorner\Datagrid\Core\Column;
use CodeCorner\Datagrid\Core\Grid;
use CodeCorner\Datagrid\Core\Operators\OperatorRegistry;
use CodeCorner\Datagrid\Exceptions\InvalidFilterException;
use CodeCorner\Datagrid\Exceptions\InvalidGridRequest;
use CodeCorner\Datagrid\Support\Like;
use CodeCorner\Datagrid\Support\ResultCache;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Expression;
use LogicException;
use Stringable;
use UnitEnum;

/**
 * Resolver-based query engine.
 *
 * Joins are lazy and deduplicated per relation; the count query is built BEFORE
 * display/sort joins are added, so it only carries what filtering needs; sorting
 * always ends with the primary key as a stable tiebreaker.
 */
class QueryEngine
{
    public function __construct(private OperatorRegistry $operators) {}

    /**
     * @param  array<string, Column>  $columns  columns the actor is allowed to see (keyed by key)
     * @return array{data: list<array>, meta: array}
     */
    public function run(Grid $grid, array $columns, mixed $actor, DataRequest $req): array
    {
        $query = $grid->query();
        if ($grid instanceof ScopesQuery) {
            $query = $grid->scope($query, $actor) ?? $query;
        }

        $ctx = new QueryContext($query, $columns);

        // 1. Row restriction (search + filters). Joins registered here are the only ones the count needs.
        if ($req->search !== '') {
            $this->applySearch($ctx, $req->search);
        }
        if ($req->filter !== null) {
            $this->applyNode($ctx, $ctx->query, $req->filter, 'and');
        }

        // 2. Count (stripped: no display selects, no sort joins). Reused across pages.
        $total = $req->includeTotal
            ? ResultCache::remember(
                ResultCache::activeGrid($grid->key()),
                ResultCache::actorKey($actor),
                ['search' => $req->search, 'filter' => $req->filter],
                fn () => (clone $ctx->query)->toBase()->getCountForPagination(),
                'count',
            )
            : null;

        // 3. Selection, ordering, paging.
        $display = $this->displayColumns($ctx, $req);
        $this->applySelect($ctx, $display);
        $this->applySort($ctx, $req, $grid->primaryKey());

        $limit = $req->perPage + ($req->includeTotal ? 0 : 1);
        $rows = $ctx->query->offset(($req->page - 1) * $req->perPage)->limit($limit)->get();

        $hasMore = $req->includeTotal ? ($total > $req->page * $req->perPage) : $rows->count() > $req->perPage;
        if (! $req->includeTotal) {
            $rows = $rows->take($req->perPage);
        }

        $pk = $grid->primaryKey();

        return [
            'data' => $rows->map(fn (Model $m) => [
                'id' => $m->getAttribute($pk),
                'values' => $this->rowValues($m, $display),
            ])->values()->all(),
            'meta' => [
                'page' => $req->page,
                'perPage' => $req->perPage,
                'total' => $total,
                'lastPage' => $total === null ? null : max(1, (int) ceil($total / $req->perPage)),
                'hasMore' => $hasMore,
            ],
        ];
    }

    // ---- filtering ---------------------------------------------------------------

    private function applyNode(QueryContext $ctx, Builder $q, array $node, string $boolean): void
    {
        if (array_key_exists('children', $node)) {
            if ($node['children'] === []) {
                return;
            }
            $q->where(function (Builder $w) use ($ctx, $node) {
                foreach ($node['children'] as $child) {
                    $this->applyNode($ctx, $w, $child, $node['logic']);
                }
            }, null, null, $boolean);

            return;
        }

        $this->applyCondition($ctx, $q, $node, $boolean);
    }

    private function applyCondition(QueryContext $ctx, Builder $q, array $c, string $boolean): void
    {
        $column = $ctx->columns[$c['field']] ?? null;

        // Unknown, forbidden and non-filterable fields all look the same to the caller.
        if ($column === null || $column->getFilter() === null) {
            throw new InvalidFilterException("Field [{$c['field']}] cannot be filtered.");
        }
        if (! in_array($c['op'], $column->getFilter()->allowedOperators($this->operators), true)) {
            throw new InvalidFilterException("Operator [{$c['op']}] is not allowed on [{$c['field']}].");
        }

        $operator = $this->operators->get($c['op']);

        if ($callback = $column->filterCallback()) {
            $q->where(function (Builder $w) use ($callback, $c) {
                $callback($w, $c['op'], $c['value']);
            }, null, null, $boolean);

            return;
        }

        switch ($column->kind()) {
            case 'aggregate':
                throw new InvalidFilterException(
                    "Filtering aggregate column [{$column->key()}] needs ->filterable(using: ...) in this version."
                );

            case 'relation':
                if ($this->isMany($ctx, $column)) {
                    // To-many: EXISTS, never a join (a join would duplicate rows).
                    $related = $this->relationOf($ctx, $column->relationName())->getRelated()->getTable();
                    $method = $boolean === 'or' ? 'orWhereHas' : 'whereHas';
                    $q->{$method}($column->relationName(), function (Builder $sub) use ($operator, $related, $column, $c) {
                        $operator->apply($sub, $related.'.'.$column->attributeName(), $c['value'], 'and');
                    });

                    return;
                }
                break;
        }

        $operator->apply($q, $this->expression($ctx, $column), $c['value'], $boolean);
    }

    private function applySearch(QueryContext $ctx, string $term): void
    {
        $searchable = array_filter(
            $ctx->columns,
            fn (Column $c) => $c->isSearchable()
                && $c->kind() !== 'aggregate'
                && ! ($c->kind() === 'relation' && $this->isMany($ctx, $c))
        );

        if ($searchable === []) {
            return;
        }

        $pattern = Like::pattern($term);
        $exprs = array_map(fn (Column $c) => $this->expression($ctx, $c), array_values($searchable));

        $ctx->query->where(function (Builder $w) use ($exprs, $pattern) {
            foreach ($exprs as $expr) {
                Like::apply($w, $expr, $pattern, 'or');
            }
        });
    }

    // ---- select / sort -----------------------------------------------------------

    /** @return list<Column> */
    private function displayColumns(QueryContext $ctx, DataRequest $req): array
    {
        $wanted = $req->columns !== []
            ? array_values(array_filter(array_map(fn ($k) => $ctx->columns[$k] ?? null, $req->columns)))
            : array_values(array_filter($ctx->columns, fn (Column $c) => $c->isVisibleByDefault()));

        // Hidden columns are never sent; to-many display is not supported yet.
        return array_values(array_filter(
            $wanted,
            fn (Column $c) => ! $c->isHidden() && ! ($c->kind() === 'relation' && $this->isMany($ctx, $c))
        ));
    }

    /** @param list<Column> $display */
    private function applySelect(QueryContext $ctx, array $display): void
    {
        $ctx->query->select($ctx->table.'.*');
        $grammar = $ctx->query->getQuery()->getGrammar();

        foreach ($display as $c) {
            switch ($c->kind()) {
                case 'aggregate':
                    $this->ensureAggregate($ctx, $c);
                    break;
                case 'relation':
                case 'computed':
                    $expr = $this->expression($ctx, $c);
                    $sql = $expr instanceof Expression ? $expr->getValue($grammar) : $grammar->wrap($expr);
                    $ctx->query->addSelect(new Expression($sql.' as '.$grammar->wrap($c->alias())));
                    break;
            }
        }
    }

    private function applySort(QueryContext $ctx, DataRequest $req, string $pk): void
    {
        $sorts = $req->sort;

        if ($sorts === []) {
            foreach ($ctx->columns as $c) {
                if ($c->getDefaultSort() !== null) {
                    $sorts[] = ['field' => $c->key(), 'dir' => $c->getDefaultSort()];
                }
            }
        }

        $sortedPk = false;
        foreach ($sorts as $s) {
            $c = $ctx->columns[$s['field']] ?? null;
            if ($c === null || ! $c->isSortable()) {
                throw new InvalidGridRequest("Field [{$s['field']}] cannot be sorted.");
            }
            if ($c->kind() === 'relation' && $this->isMany($ctx, $c)) {
                throw new InvalidGridRequest("To-many column [{$c->key()}] cannot be sorted.");
            }

            if ($c->kind() === 'aggregate') {
                $this->ensureAggregate($ctx, $c);
                $ctx->query->orderBy($c->alias(), $s['dir']);
            } else {
                $ctx->query->orderBy($this->expression($ctx, $c), $s['dir']);
            }

            $sortedPk = $sortedPk || ($c->kind() === 'direct' && $c->attributeName() === $pk);
        }

        // Stable pagination: never page over a non-deterministic order.
        if (! $sortedPk) {
            $ctx->query->orderBy($ctx->table.'.'.$pk, 'asc');
        }
    }

    // ---- resolvers ---------------------------------------------------------------

    /** SQL expression for a non-aggregate, non-many column. */
    private function expression(QueryContext $ctx, Column $c): string|Expression
    {
        return match ($c->kind()) {
            'computed' => new Expression('('.$c->computedSql().')'),
            'relation' => $this->joinAlias($ctx, $c->relationName()).'.'.$c->attributeName(),
            'direct' => $ctx->table.'.'.$c->attributeName(),
            default => throw new LogicException("Column [{$c->key()}] has no direct SQL expression."),
        };
    }

    private function joinAlias(QueryContext $ctx, string $name): string
    {
        if (isset($ctx->joins[$name])) {
            return $ctx->joins[$name];
        }

        $rel = $this->relationOf($ctx, $name);
        $alias = 'j_'.preg_replace('/\W+/', '_', $name);
        $table = $rel->getRelated()->getTable();

        if ($rel instanceof BelongsTo) {
            $ctx->query->leftJoin("{$table} as {$alias}", "{$alias}.".$rel->getOwnerKeyName(), '=', $rel->getQualifiedForeignKeyName());
        } elseif ($rel instanceof HasOne) {
            $ctx->query->leftJoin("{$table} as {$alias}", "{$alias}.".$rel->getForeignKeyName(), '=', $rel->getQualifiedParentKeyName());
        } else {
            throw new LogicException('Relation ['.$name.'] of type '.class_basename($rel).' is not supported for joins.');
        }

        return $ctx->joins[$name] = $alias;
    }

    private function ensureAggregate(QueryContext $ctx, Column $c): void
    {
        if (isset($ctx->aggregates[$c->key()])) {
            return;
        }
        $a = $c->aggregateSpec();
        $ctx->query->withAggregate("{$a['relation']} as {$c->alias()}", $a['column'], $a['fn']);
        $ctx->aggregates[$c->key()] = true;
    }

    private function relationOf(QueryContext $ctx, string $name): Relation
    {
        if (! method_exists($ctx->model, $name)) {
            throw new LogicException('Relation ['.$name.'] does not exist on '.get_class($ctx->model).'.');
        }
        $rel = $ctx->model->{$name}();

        return $rel instanceof Relation ? $rel : throw new LogicException("[{$name}] is not an Eloquent relation.");
    }

    private function isMany(QueryContext $ctx, Column $c): bool
    {
        $rel = $this->relationOf($ctx, $c->relationName());

        return $rel instanceof HasMany || $rel instanceof BelongsToMany
            || $rel instanceof MorphMany || $rel instanceof HasManyThrough;
    }

    // ---- output ------------------------------------------------------------------

    /** @param list<Column> $display */
    private function rowValues(Model $m, array $display): array
    {
        $out = [];
        foreach ($display as $c) {
            if ($c->hasOptions() && $c->kind() === 'direct') {
                // Option columns must keep the stored key so the menu can match it.
                $value = $m->getAttribute($c->attributeName());
            } elseif ($cb = $c->valueCallback()) {
                $value = $cb($m);
            } else {
                $value = $m->getAttribute($c->kind() === 'direct' ? $c->attributeName() : $c->alias());
            }
            $out[$c->key()] = $this->normalize($value);
        }

        return $out;
    }

    private function normalize(mixed $v): mixed
    {
        return match (true) {
            $v instanceof BackedEnum => $v->value,
            $v instanceof UnitEnum => $v->name,
            $v instanceof DateTimeInterface => $v->format(DATE_ATOM),
            $v instanceof Stringable => (string) $v,
            default => $v,
        };
    }
}
