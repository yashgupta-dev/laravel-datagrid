# Datagrid guide

A grid is a PHP class. The browser loads its schema, then asks the server for rows. Column rules, permissions, export, and import are enforced on the server. Hiding a button in the browser is not the security check.

Register the class in `config/datagrid.php`:

```php
'grids' => [
    'users' => App\Grids\UserGrid::class,
],
```

Show it in a Blade view:

```blade
<x-datagrid grid="users" title="Users" :per-page="25" />
```

Publish the browser assets after you pull a new package version:

```bash
php artisan vendor:publish --tag=datagrid-assets --force
```

Export, import, and bulk actions run on the queue. Run a worker (`php artisan queue:work`) when those features are turned on.

## Columns

Each column starts with `Column::make('field')`. Chain only the behavior you want.

```php
Column::make('name')->label('Name')
    ->sortable()
    ->searchable()
    ->filterable()
    ->pinned('left')
    ->width(180)
    ->copy()
    ->editable('required|string|max:255')
    ->exportable()
    ->importable()
```

| Call | What it does |
| --- | --- |
| `label('Name')` | Header text. The default is a headline of the field name. |
| `type('date')` | How the cell is drawn: `text`, `number`, `date`, `datetime`, `boolean`, `money`, `link`, `image`, `select`. |
| `description('…')` | Hint on the header. |
| `sortable()` | Click the header, or use Sort, to order by this column. |
| `searchable()` | The search box includes this column. |
| `filterable()` | The column appears in the Filter dialog. |
| `pinned('left')` or `pinned('right')` | The column stays visible while the table scrolls sideways. |
| `width(180)` | Starting width in pixels. |
| `visible(false)` | Hidden until someone turns it on in Columns. |
| `toggleable(false)` | The viewer cannot hide this column. |
| `hidden()` | The column is never sent to the browser. You can still filter on it. |
| `copy()` | A copy button on the cell. |
| `defaultSort('asc')` | The grid opens sorted by this column. |
| `using(fn ($row) => …)` | Replaces the displayed value. The callback receives the model. Do not use it to rename option keys; the option list does that. |
| `exportable(false)` | Leave the column out of CSV export. A closure formats the exported value instead: `exportable(fn ($value, $row) => …)`. |
| `importable(false)` | Leave the column out of CSV import. |

`password`, `hidden`, and `secret` types are never sortable, searchable, exported, imported, copied, or edited.

### Relations, totals, and SQL

```php
Column::make('company.name')->relation('company')->label('Company')->sortable()->searchable()->filterable();
Column::make('orders_total')->aggregate('orders', 'sum', 'amount')->label('Order total')->type('money')->sortable();
Column::make('initials')->computed('UPPER(LEFT(users.name, 1))');
```

`relation()` joins a `belongsTo` or `hasOne`. `aggregate()` adds a subselect. `computed()` is raw SQL that you write; the grid does not accept SQL from the browser.

### Images and links

```php
Column::make('avatar')->image(40, 40)->exportable(false)->importable(false);
Column::make('website')->type('link');
```

`image(40, 40)` is the display size in pixels, from 16 to 240. The file is not resized on the server. A link cell opens only `http`, `https`, or a site path.

### Choices

```php
Column::make('status')->options([
    '1' => 'Active',
    '0' => 'Disabled',
])->editable('required|in:1,0');

Column::make('skills')->options([
    'php' => 'PHP',
    'laravel' => 'Laravel',
])->multiple()->editable(['nullable', 'array', 'max:20']);
```

The cell shows a menu of those labels. The server rejects any value that is not in the list. `multiple()` stores several values. In a CSV import, send them as `php|laravel` or as a JSON array.

If the column is filterable, the Filter dialog offers the same choices when that column is selected.

## Editing a column

`editable()` turns on inline edit and sets the Laravel validation rules. Rules stay on the server. Double-click a text cell, or use the menu on a choice cell.

Anyone who can see the column can edit it, until you add `canEdit()`:

```php
Column::make('status')->options([
    '1' => 'Active',
    '0' => 'Disabled',
])->editable('required|in:1,0')
->canEdit(fn ($actor) => $actor?->can('datagrid.users.status.update'));
```

`canEdit()` accepts the same kinds of rule as `can()`: a permission name, a closure, or an enum. The closure receives the current actor.

Leave `canEdit()` off and the column behaves as before. People who fail the check still see the column, but the edit control is gone, import will not write that field, and a direct update request is rejected.

`can()` is different. It hides the column completely from people who fail the check:

```php
Column::make('salary')->can(fn ($actor) => $actor?->can('datagrid.users.salary.view'));
```

## Filters, search, and sort

Search covers every `searchable()` column. Sort uses every `sortable()` column. Filter uses every `filterable()` column.

Custom filter logic:

```php
Column::make('skills')->filterable(using: function ($query, string $op, mixed $value) {
    foreach ((array) $value as $item) {
        $query->orWhereJsonContains('skills', $item);
    }
});
```

Saved filters store a name plus the current search and filter rules for the signed-in person. Guests do not get saved filters.

## Toolbar

On a wide screen the bar shows your buttons, then Saved, Filter, Sort, Export, and Import, in that order. On a narrow screen the most-used controls stay visible and the rest fold into More.

The Columns control at the right of the header shows, hides, and reorders columns. Up and down move a column. That order is remembered in the browser. Reset columns restores the order from the PHP class. Pin left and pin right are on each column's own menu.

## Buttons and row actions

```php
public function buttons(): array
{
    return [
        Button::make('create')->label('Create')->route('users.create')->icon('plus')->color('primary'),
        Button::dropdown('more', 'More', [
            Button::make('invite')->label('Invite')->route('users.invite')->icon('mail'),
        ]),
    ];
}

public function actions(): array
{
    return [
        Action::make('edit')->label('Edit')->row()
            ->route('users.edit', ['user' => '{id}'])->icon('pencil'),
        Action::make('delete')->label('Delete')->row()->bulk()->danger()
            ->confirm('Delete the selected users?')
            ->handle(function (Collection $models) {
                $count = $models->count();
                $models->each->delete();

                return "Deleted {$count} users.";
            }),
    ];
}
```

`row()` puts the action on each row. `bulk()` runs it on the selected rows through the queue. `route()` builds a link. `{id}` is replaced with the row id. `can('permission.name')` hides a button or action from people who fail that permission. `canEdit('permission.name')` does the same for inline cell edits.

Icons: `plus`, `pencil`, `trash`, `mail`, `check`. Colors: `primary`, `neutral`, `danger`.

## Export and import

Both are on unless the grid turns them off:

```php
public function exportable(): bool
{
    return true;
}

public function importable(): bool
{
    return false;
}
```

Return `false` from `importable()` for export only. Return `false` from both when the grid should have neither. The matching HTTP request is rejected as well.

A column is included in export or import only when that column calls `exportable()` or `importable()`. Import updates existing rows and needs a column mapped to the primary key.

## Permissions

Edit one list in `config/datagrid.php`. The grid, the cell editor, and the create and edit pages all read it.

```php
'permissions' => [
    'users' => [
        'view' => null,
        'create' => 'datagrid.users.create',
        'update' => 'datagrid.users.update',
        'delete' => 'datagrid.users.delete',
        'verify' => 'datagrid.users.verify',
        'columns' => [
            'status' => 'datagrid.users.status.update',
        ],
    ],
],
```

`null` means that action has no extra permission. A column that is not listed uses `update`. Set a column to `null` when everyone who can see it may edit it. Status is the exception: it has its own permission.

In the grid, ask for those names instead of typing the permission string again:

```php
public function ability(): mixed
{
    return Permission::for('users', 'view');
}

Button::make('create')->route('users.create')->can(Permission::for('users', 'create'));

Action::make('edit')->row()->route('users.edit', ['user' => '{id}'])
    ->can(Permission::for('users', 'update'));

Column::make('name')->editable('required|string|max:255')
    ->canEdit(Permission::column('users', 'name'));
```

`can()` hides Create, Edit, Delete, or Verify. `canEdit()` leaves the column on screen and blocks the cell update and an import of that field. Protect the real pages with the same names:

```php
Route::get('/users/create', ...)->middleware('datagrid.can:users,create');
Route::get('/users/{user}/edit', ...)->middleware('datagrid.can:users,update');
```

The check goes to `App\Datagrid\RbacAuthorizer`. It tries the signed-in user in this order: `hasPermissionTo()` (Spatie), `hasPermission()` (your own method), then `can()` (a Laravel gate). Swap the RBAC by changing that class, not the grids.

`authorization.allow_guests` controls a visitor with no user. `true` lets the demo keep working. Set it to `false` when a signed-in user is required. `authorization.driver` stays `custom`. `none` allows every request and ignores this list. `php artisan datagrid:doctor` warns when production is still on `none` with no auth middleware.

Columns and actions that do not call `can()` or `canEdit()` stay open.

Limit which rows exist for a person by implementing `ScopesQuery`:

```php
public function scope(Builder $query, mixed $actor): Builder
{
    return $query->where('company_id', $actor->company_id);
}
```

The scope applies to the grid, the count, export, and bulk actions.
