<?php

namespace CodeCorner\Datagrid\Core;

use CodeCorner\Datagrid\Auth\AuthContext;
use CodeCorner\Datagrid\Auth\AuthManager;
use CodeCorner\Datagrid\Auth\Authorizer;
use CodeCorner\Datagrid\Contracts\ScopesQuery;
use CodeCorner\Datagrid\Core\Operators\OperatorRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

abstract class Grid
{
    public const SCHEMA_VERSION = 1;

    /** @var array<string, Column>|null */
    private ?array $map = null;

    abstract public function query(): Builder;

    /** @return list<Column> */
    abstract public function columns(): array;

    /** @return list<Action> */
    public function actions(): array
    {
        return [];
    }

    /** @return list<Button> */
    public function buttons(): array
    {
        return [];
    }

    /** Show the Export button and accept export requests. */
    public function exportable(): bool
    {
        return true;
    }

    /** Show the Import button and accept import requests. */
    public function importable(): bool
    {
        return true;
    }

    public function key(): string
    {
        $base = class_basename(static::class);

        return Str::kebab(Str::beforeLast($base, 'Grid') ?: $base);
    }

    /** Ability required to open this grid at all (string, enum, Closure or null). */
    public function ability(): mixed
    {
        return null;
    }

    /** Per-grid authorizer override. */
    public function authorizer(): ?Authorizer
    {
        return null;
    }

    public function primaryKey(): string
    {
        return $this->query()->getModel()->getKeyName();
    }

    /** Grid query with the row scope applied, when the grid defines one. */
    public function scopedQuery(mixed $actor): Builder
    {
        $query = $this->query();
        if ($this instanceof ScopesQuery) {
            $query = $this->scope($query, $actor) ?? $query;
        }

        return $query;
    }

    /** @return array<string, Column> */
    public function columnMap(): array
    {
        if ($this->map === null) {
            $this->map = [];
            foreach ($this->columns() as $column) {
                $this->map[$column->key()] = $column;
            }
        }

        return $this->map;
    }

    /** @return array<string, Action> */
    public function actionMap(): array
    {
        $map = [];
        foreach ($this->actions() as $action) {
            $map[$action->key()] = $action;
        }

        return $map;
    }

    public function findAction(string $key): ?Action
    {
        return $this->actionMap()[$key] ?? null;
    }

    /** @return list<Action> */
    public function allowedActions(mixed $actor, AuthManager $auth): array
    {
        return array_values(array_filter(
            $this->actionMap(),
            fn (Action $action) => $auth->allows(
                $actor,
                $action->getAbility(),
                new AuthContext($this->key(), AuthContext::ACTION, $action->key()),
                $this->authorizer()
            )
        ));
    }

    /** @return list<array<string, mixed>> */
    public function allowedButtons(mixed $actor, AuthManager $auth): array
    {
        $buttons = [];
        foreach ($this->buttons() as $button) {
            if (! $button instanceof Button) {
                continue;
            }
            $visible = $this->authorizeButton($button, $actor, $auth);
            $schema = $visible?->toSchema();
            if ($schema !== null) {
                $buttons[] = $schema;
            }
        }

        return $buttons;
    }

    private function authorizeButton(Button $button, mixed $actor, AuthManager $auth): ?Button
    {
        if (! $auth->allows(
            $actor,
            $button->getAbility(),
            new AuthContext($this->key(), AuthContext::ACTION, $button->key()),
            $this->authorizer()
        )) {
            return null;
        }

        if (! $button->isDropdown()) {
            return $button;
        }

        $items = [];
        foreach ($button->items() as $item) {
            if ($item->isDropdown()) {
                continue;
            }
            $allowed = $this->authorizeButton($item, $actor, $auth);
            if ($allowed !== null) {
                $items[] = $allowed;
            }
        }

        return $items === [] ? null : $button->replaceItems($items);
    }

    public function canOpen(mixed $actor, AuthManager $auth): bool
    {
        return $auth->allows($actor, $this->ability(), new AuthContext($this->key(), AuthContext::GRID), $this->authorizer());
    }

    /** True when this actor may change the column. No canEdit() rule means every viewer may edit it. */
    public function canEditColumn(Column $column, mixed $actor, AuthManager $auth): bool
    {
        $ability = $column->getEditAbility();
        if ($ability === null) {
            return true;
        }

        return $auth->allows(
            $actor,
            $ability,
            new AuthContext($this->key(), AuthContext::EDIT, $column->key()),
            $this->authorizer()
        );
    }

    /** Columns this actor may see. The single source for schema, filter, sort, search. */
    public function allowedColumns(mixed $actor, AuthManager $auth): array
    {
        return array_filter(
            $this->columnMap(),
            fn (Column $c) => $auth->allows(
                $actor,
                $c->getAbility(),
                new AuthContext($this->key(), AuthContext::COLUMN, $c->key()),
                $this->authorizer()
            )
        );
    }

    public function schema(mixed $actor, AuthManager $auth, OperatorRegistry $operators, array $limits = []): array
    {
        $columns = $this->allowedColumns($actor, $auth);

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'key' => $this->key(),
            'primaryKey' => $this->primaryKey(),
            // Hidden columns are filterable server-side but never exposed as display columns.
            'columns' => array_values(array_map(
                function (Column $c) use ($actor, $auth, $operators) {
                    $schema = $c->toSchema($operators);
                    if (! $this->canEditColumn($c, $actor, $auth)) {
                        $schema['editable'] = false;
                        $schema['importable'] = false;
                    }

                    return $schema;
                },
                array_filter($columns, fn (Column $c) => ! $c->isHidden())
            )),
            'actions' => array_map(
                fn (Action $action) => $action->toSchema(),
                $this->allowedActions($actor, $auth)
            ),
            'buttons' => $this->allowedButtons($actor, $auth),
            'features' => [
                'export' => $this->exportable(),
                'import' => $this->importable(),
            ],
            'limits' => [
                'defaultPerPage' => $limits['default_per_page'] ?? 25,
                'maxPerPage' => $limits['max_per_page'] ?? 200,
                'maxActionIds' => $limits['max_action_ids'] ?? 500,
            ],
        ];
    }
}
