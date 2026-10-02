<?php

namespace CodeCorner\Datagrid\Tests\Fixtures;

use CodeCorner\Datagrid\Auth\ActorResolver;
use CodeCorner\Datagrid\Core\Action;
use CodeCorner\Datagrid\Core\Button;
use CodeCorner\Datagrid\Core\Column;
use CodeCorner\Datagrid\Core\Grid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

require_once __DIR__.'/Models.php';

class UserGrid extends Grid
{
    /** @var list<int|string> */
    public static array $ran = [];

    public function query(): Builder
    {
        return User::query();
    }

    public function actions(): array
    {
        return [
            Action::make('touch')->label('Touch')->row()->bulk()->handle(function (Collection $models) {
                self::$ran = $models->pluck('id')->all();

                return 'Touched '.$models->count();
            }),
            Action::make('purge')->label('Purge')->row()->bulk()->can(fn () => false)->handle(function () {
                self::$ran = ['purge'];

                return 'purged';
            }),
            Action::make('edit')->label('Edit')->row()
                ->route('users.edit', ['user' => '{id}'])->icon('pencil'),
        ];
    }

    public function buttons(): array
    {
        return [
            Button::make('create')->label('Create')->route('users.create')->icon('plus')->color('primary'),
            Button::dropdown('more', 'More', [
                Button::make('invite')->label('Invite')->route('users.invite')->icon('mail')->color('neutral'),
                Button::make('hidden')->label('Hidden')->route('users.invite')->can(fn () => false),
            ]),
        ];
    }

    public function columns(): array
    {
        return [
            Column::make('name')->sortable()->searchable()->filterable()->copy()->editable('required|string|max:255'),
            Column::make('email')->searchable()->filterable(),
            Column::make('created_at')->label('Created')->type('date')->exportable(false)->importable(false),
            Column::make('role.name')->relation('role')->label('Role')->sortable()->searchable()->filterable(),
            Column::make('posts.title')->relation('posts')->filterable(),
            Column::make('posts_views')->aggregate('posts', 'sum', 'views')->type('number')->sortable(),
            Column::make('salary')->can(fn ($actor) => $actor === 'hr'),
            Column::make('avatar')->image(48, 32)->exportable(false)->importable(false),
            Column::make('status')->options([
                'active' => 'Active',
                'disabled' => 'Disabled',
            ])->filterable()->editable('required|in:active,disabled'),
            Column::make('tags')->options([
                'billing' => 'Billing',
                'support' => 'Support',
            ])->multiple()->editable(['nullable', 'array', 'max:20']),
        ];
    }
}

class ExportOnlyGrid extends UserGrid
{
    public function importable(): bool
    {
        return false;
    }
}

class GuardedEditGrid extends UserGrid
{
    public function columns(): array
    {
        return array_map(function (Column $column) {
            if ($column->key() !== 'email') {
                return $column;
            }

            return Column::make('email')->editable(['required', 'email'])->canEdit(fn ($actor) => $actor === 'editor');
        }, parent::columns());
    }
}

class HeaderActorResolver implements ActorResolver
{
    public function resolve(Request $request): mixed
    {
        $actor = $request->header('X-Actor');

        return $actor === null || $actor === '' ? null : $actor;
    }
}

class PlainGrid extends UserGrid
{
    public function exportable(): bool
    {
        return false;
    }

    public function importable(): bool
    {
        return false;
    }
}
