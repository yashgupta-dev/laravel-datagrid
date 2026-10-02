# codecorner/laravel-datagrid

Extensible data grid for Laravel. The grid is defined once in PHP; the server exposes a
**schema** endpoint and a **data** endpoint, and thin renderers (Next.js/React, Blade) consume them.

> Status: **v0.1 in development** (query engine, filters, search, sorting, pagination, pluggable authorization).
> Saved views, queued bulk actions, export and import are on the roadmap.

## Install

```bash
composer require codecorner/laravel-datagrid
php artisan vendor:publish --tag=datagrid-config
```

## Define a grid

```php
use CodeCorner\Datagrid\Core\{Column, Grid};
use CodeCorner\Datagrid\Core\Filters\Filter;

class UserGrid extends Grid
{
    public function query(): Builder { return User::query(); }

    public function columns(): array
    {
        return [
            Column::make('name')->sortable()->searchable()->filterable(),
            Column::make('role.name')->relation('role')->sortable()->filterable(),   // lazy LEFT JOIN
            Column::make('posts.title')->relation('posts')->filterable(),            // EXISTS, no row duplication
            Column::make('posts_views')->aggregate('posts', 'sum', 'views')->sortable(),
            Column::make('salary')->can(fn ($actor) => $actor?->isHr()),             // column permission
        ];
    }
}
```

Register it in `config/datagrid.php` (`'grids' => ['users' => UserGrid::class]`). Endpoints:

- `GET  /datagrid/{grid}/schema`
- `POST /datagrid/{grid}/data`

```json
{
  "page": 1, "perPage": 25, "includeTotal": true,
  "search": "ali",
  "sort": [{"field": "name", "dir": "asc"}],
  "columns": ["name", "role.name"],
  "filter": {"logic": "and", "children": [
    {"field": "role.name", "op": "in", "value": ["Admin"]},
    {"logic": "or", "children": [
      {"field": "name", "op": "starts_with", "value": "A"},
      {"field": "posts.title", "op": "eq", "value": "Hello"}
    ]}
  ]}
}
```

## Render the UI

### Blade (no Node build needed)

```bash
php artisan vendor:publish --tag=datagrid-assets   # copies JS to public/vendor/datagrid
```

```blade
<x-datagrid grid="users" :per-page="25" />
```

Custom cell renderer (any `<script type="module">`):

```js
import { registerCell } from '/vendor/datagrid/datagrid.js';
registerCell('status', (value) => { const b = document.createElement('b'); b.textContent = value; return b; });
```

### Next.js

```bash
npm i @codecorner/datagrid-core @codecorner/datagrid-react
```

```jsx
'use client';
import { DataGrid } from '@codecorner/datagrid-react';

export default function UsersPage() {
  return <DataGrid url={`${process.env.NEXT_PUBLIC_API}/datagrid/users`}
                   credentials="include" headers={{ Authorization: `Bearer ${token}` }}
                   cells={{ status: (v) => <b>{v}</b> }} />;
}
```

Cross-origin Next.js -> Laravel needs CORS configured (`config/cors.php`, path `datagrid/*`).

Sources: `packages/core` (state + API client), `packages/react`, `resources/dist` (Blade renderer).
After editing the core run `node scripts/build-assets.mjs` to sync it into `resources/dist`.

A walkthrough of every feature is in [GUIDE.md](GUIDE.md).

## Authorization is pluggable

The grid only asks `Authorizer::allows($actor, $ability, $context)`. Drivers: `none`, `gate`,
`callback`, `custom` (your own class). `Column::can()` accepts a string, enum or closure.
Row-level scoping: implement `ScopesQuery` on the grid. Run `php artisan datagrid:doctor` to catch
an unprotected setup.

## Safety model

Fields, operators, depth, condition count, page size and sort count are whitelisted/limited server-side;
unknown, forbidden and non-filterable fields are rejected identically; LIKE input is escaped; raw SQL
only comes from developer-defined `computed()` columns.

## Known limitations (v0.1)

- Filtering on aggregate columns requires `filterable(using: ...)`.
- To-many relation columns can be filtered but not displayed or sorted.
- Joins support `BelongsTo` and `HasOne`; a `HasOne` with several matches can duplicate rows.
- Offset pagination only (cursor planned).

## Testing

```bash
composer install && vendor/bin/pest
```
