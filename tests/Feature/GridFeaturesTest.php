<?php

use CodeCorner\Datagrid\Auth\AuthManager;
use CodeCorner\Datagrid\Core\Column;
use CodeCorner\Datagrid\Tests\Fixtures\Actor;
use CodeCorner\Datagrid\Tests\Fixtures\ExportOnlyGrid;
use CodeCorner\Datagrid\Tests\Fixtures\GuardedEditGrid;
use CodeCorner\Datagrid\Tests\Fixtures\HeaderActorResolver;
use CodeCorner\Datagrid\Tests\Fixtures\PlainGrid;
use CodeCorner\Datagrid\Tests\Fixtures\ScopedUserGrid;
use CodeCorner\Datagrid\Tests\Fixtures\User;
use CodeCorner\Datagrid\Tests\Fixtures\UserGrid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../Fixtures/UserGrid.php';
require_once __DIR__.'/../Fixtures/ScopedUserGrid.php';
require_once __DIR__.'/../Fixtures/Actor.php';

beforeEach(function () {
    UserGrid::$ran = [];
    config(['datagrid.grids' => ['users' => UserGrid::class, 'scoped' => ScopedUserGrid::class]]);
});

it('rejects a queued bulk action whose ids are outside the grid', function () {
    $bob = User::create(['name' => 'Bob', 'email' => 'bob@x.test']);

    $queued = $this->postJson('/datagrid/scoped/actions/touch', [
        'ids' => [$bob->id],
        'bulk' => true,
    ])->assertOk();

    $this->getJson('/datagrid/scoped/jobs/'.$queued->json('job'), ['X-Datagrid-Token' => $queued->json('token')])
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    expect(UserGrid::$ran)->toBe([]);
});

it('exports the selected columns to csv', function () {
    User::create(['name' => 'Alice', 'email' => 'alice@x.test']);

    $queued = $this->postJson('/datagrid/users/export', [
        'columns' => ['name', 'email'],
    ])->assertOk()->assertJsonPath('status', 'queued');

    $this->getJson('/datagrid/users/jobs/'.$queued->json('job'), ['X-Datagrid-Token' => $queued->json('token')])
        ->assertOk()
        ->assertJsonPath('status', 'done');

    $this->getJson('/datagrid/users/jobs/'.$queued->json('job'))->assertNotFound();

    $download = $this->withHeaders(['X-Datagrid-Token' => $queued->json('token')])
        ->get('/datagrid/users/jobs/'.$queued->json('job').'/download')->assertOk();

    expect($download->getFile()->getContent())->toContain('Alice')->toContain('alice@x.test');
});

it('imports csv updates and reports invalid rows', function () {
    $alice = User::create(['name' => 'Alice', 'email' => 'alice@x.test']);

    $file = UploadedFile::fake()->createWithContent('users.csv', "User,Full name\n{$alice->id},Alicia\n{$alice->id},\n");
    $queued = $this->post('/datagrid/users/import', [
        'file' => $file,
        'mapping' => json_encode(['User' => 'id', 'Full name' => 'name']),
    ])->assertOk();

    $this->getJson('/datagrid/users/jobs/'.$queued->json('job'), ['X-Datagrid-Token' => $queued->json('token')])
        ->assertOk()
        ->assertJsonPath('status', 'done');

    expect($alice->fresh()->name)->toBe('Alicia');
});

it('updates a cell and returns validation errors', function () {
    $alice = User::create(['name' => 'Alice', 'email' => 'alice@x.test']);

    $this->patchJson('/datagrid/users/rows/'.$alice->id, ['field' => 'name', 'value' => ''])
        ->assertStatus(422);

    $this->patchJson('/datagrid/users/rows/'.$alice->id, ['field' => 'name', 'value' => 'Alicia'])
        ->assertOk()
        ->assertJsonPath('value', 'Alicia');

    $this->patchJson('/datagrid/users/rows/'.$alice->id, ['field' => 'email', 'value' => 'nope'])
        ->assertStatus(422);

    expect($alice->fresh()->name)->toBe('Alicia');
});

it('saves, renames, and removes filters for the signed-in actor', function () {
    $user = User::create(['name' => 'Ada', 'email' => 'ada@x.test']);
    $this->actingAs(Actor::query()->find($user->id));

    $this->getJson('/datagrid/users/filters')->assertOk()->assertJsonPath('data', []);

    $created = $this->postJson('/datagrid/users/filters', [
        'name' => 'Alices',
        'filters' => [['field' => 'name', 'op' => 'eq', 'value' => 'Alice']],
        'search' => 'ali',
    ])->assertCreated()->assertJsonPath('name', 'Alices');

    $id = $created->json('id');

    $this->patchJson('/datagrid/users/filters/'.$id, [
        'name' => 'Renamed',
        'filters' => [['field' => 'name', 'op' => 'contains', 'value' => 'A']],
        'search' => '',
    ])->assertOk()->assertJsonPath('name', 'Renamed')->assertJsonPath('filters.0.op', 'contains');

    $this->getJson('/datagrid/users/filters')->assertJsonPath('data.0.name', 'Renamed');

    $this->deleteJson('/datagrid/users/filters/'.$id)->assertNoContent();
    $this->getJson('/datagrid/users/filters')->assertJsonCount(0, 'data');
});

it('prefixes spreadsheet formulas in exports', function () {
    User::create(['name' => '=1+1', 'email' => 'formula@x.test']);

    $queued = $this->postJson('/datagrid/users/export', ['columns' => ['name']])->assertOk();
    $download = $this->withHeaders(['X-Datagrid-Token' => $queued->json('token')])
        ->get('/datagrid/users/jobs/'.$queued->json('job').'/download')->assertOk();

    expect($download->getFile()->getContent())->toContain("'=1+1");
});

it('exposes toolbar buttons and substitutes the row id in action links', function () {
    Route::get('/users/create', fn () => 'create')->name('users.create');
    Route::get('/users/invite', fn () => 'invite')->name('users.invite');
    Route::get('/users/{user}/edit', fn () => 'edit')->name('users.edit');

    $schema = $this->getJson('/datagrid/users/schema')->assertOk();
    $schema->assertJsonPath('buttons.0.key', 'create')
        ->assertJsonPath('buttons.0.href', route('users.create'))
        ->assertJsonPath('buttons.0.icon', 'plus')
        ->assertJsonPath('buttons.0.color', 'primary')
        ->assertJsonPath('buttons.1.key', 'more')
        ->assertJsonPath('buttons.1.items.0.key', 'invite')
        ->assertJsonPath('buttons.1.items.0.icon', 'mail')
        ->assertJsonCount(1, 'buttons.1.items');

    $edit = collect($schema->json('actions'))->firstWhere('key', 'edit');
    expect($edit['href'])->toBe(str_replace('000000', '{id}', route('users.edit', ['user' => '000000'])))
        ->and($edit['icon'])->toBe('pencil');
});

it('skips columns that are not exportable or importable', function () {
    $alice = User::create(['name' => 'Alice', 'email' => 'alice@x.test']);

    $queued = $this->postJson('/datagrid/users/export', [
        'columns' => ['name', 'created_at'],
    ])->assertOk();
    $download = $this->withHeaders(['X-Datagrid-Token' => $queued->json('token')])
        ->get('/datagrid/users/jobs/'.$queued->json('job').'/download')->assertOk();
    $csv = $download->getFile()->getContent();
    expect($csv)->toContain('Alice')->and($csv)->not->toContain('Created');

    $file = UploadedFile::fake()->createWithContent('users.csv', "User,Created\n{$alice->id},2020-01-01\n");
    $this->post('/datagrid/users/import', [
        'file' => $file,
        'mapping' => json_encode(['User' => 'id', 'Created' => 'created_at']),
    ])->assertStatus(422);
});

it('stores only the values configured on a column', function () {
    $schema = $this->getJson('/datagrid/users/schema')->assertOk();
    $status = collect($schema->json('columns'))->firstWhere('key', 'status');
    $tags = collect($schema->json('columns'))->firstWhere('key', 'tags');
    expect($status['options'])->toBe([
        ['value' => 'active', 'label' => 'Active'],
        ['value' => 'disabled', 'label' => 'Disabled'],
    ])->and($status['multiple'])->toBeFalse()
        ->and($status['filter']['type'])->toBe('select')
        ->and($status['filter']['options'])->toBe($status['options']);
    $avatar = collect($schema->json('columns'))->firstWhere('key', 'avatar');
    expect($avatar['type'])->toBe('image')
        ->and($avatar['image'])->toBe(['width' => 48, 'height' => 32])
        ->and($tags['multiple'])->toBeTrue()
        ->and($tags['options'])->toHaveCount(2);

    $alice = User::create(['name' => 'Alice', 'email' => 'alice@x.test', 'status' => 'active']);
    $this->patchJson('/datagrid/users/rows/'.$alice->id, ['field' => 'status', 'value' => 'disabled'])
        ->assertOk()
        ->assertJsonPath('value', 'disabled');
    $this->patchJson('/datagrid/users/rows/'.$alice->id, ['field' => 'status', 'value' => 'banned'])
        ->assertStatus(422);
    $this->patchJson('/datagrid/users/rows/'.$alice->id, ['field' => 'tags', 'value' => ['billing', 'nope']])
        ->assertStatus(422);
    $this->patchJson('/datagrid/users/rows/'.$alice->id, ['field' => 'tags', 'value' => ['support', 'billing']])
        ->assertOk()
        ->assertJsonPath('value', ['support', 'billing']);
    expect($alice->fresh()->tags)->toBe(['support', 'billing']);
});

it('keeps secret column types out of import and export', function () {
    $column = Column::make('password')->type('hidden');

    expect($column->isExportable())->toBeFalse()
        ->and($column->isImportable())->toBeFalse()
        ->and($column->isCopyable())->toBeFalse()
        ->and($column->isHidden())->toBeTrue()
        ->and($column->isSearchable())->toBeFalse();
});

it('reuses the cached row count when another page is requested', function () {
    User::create(['name' => 'Ada', 'email' => 'ada@x.test']);
    User::create(['name' => 'Bea', 'email' => 'bea@x.test']);

    $this->postJson('/datagrid/users/data', ['page' => 1, 'perPage' => 1])->assertOk()->assertJsonPath('meta.total', 2);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->postJson('/datagrid/users/data', ['page' => 2, 'perPage' => 1])->assertOk()->assertJsonPath('meta.total', 2);

    $counted = collect(DB::getQueryLog())->contains(fn ($q) => str_contains(strtolower($q['query']), 'count('));
    expect($counted)->toBeFalse();
});

it('serves a cached page and a fresh page after an edit', function () {
    $alice = User::create(['name' => 'Alice', 'email' => 'alice@x.test']);

    $this->postJson('/datagrid/users/data', ['perPage' => 5])->assertOk()->assertJsonPath('data.0.values.name', 'Alice');

    $this->patchJson('/datagrid/users/rows/'.$alice->id, ['field' => 'name', 'value' => 'Alicia'])->assertOk();

    $this->postJson('/datagrid/users/data', ['perPage' => 5])->assertOk()->assertJsonPath('data.0.values.name', 'Alicia');
});

it('keeps guest filter reads empty and does not leak error details', function () {
    config(['app.debug' => true]);

    $this->get('/datagrid/users/filters')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('persisted', false)
        ->assertJsonPath('data', []);

    $missing = $this->get('/datagrid/missing/schema');
    $missing->assertNotFound();
    expect($missing->getContent())->not->toContain('trace')->and($missing->getContent())->not->toContain('vendor/');

    $this->postJson('/datagrid/users/filters', [
        'name' => 'Alices',
        'filters' => [],
        'search' => '',
    ])->assertUnauthorized()->assertJsonMissingPath('trace');
});

it('shows export and import only when the grid allows them', function () {
    config(['datagrid.grids' => [
        'users' => UserGrid::class,
        'export-only' => ExportOnlyGrid::class,
        'plain' => PlainGrid::class,
    ]]);

    $this->getJson('/datagrid/users/schema')
        ->assertOk()
        ->assertJsonPath('features.export', true)
        ->assertJsonPath('features.import', true);

    $this->getJson('/datagrid/export-only/schema')
        ->assertOk()
        ->assertJsonPath('features.export', true)
        ->assertJsonPath('features.import', false);

    $this->postJson('/datagrid/export-only/export', ['columns' => ['name']])->assertOk();
    $this->post('/datagrid/export-only/import', [
        'file' => UploadedFile::fake()->createWithContent('users.csv', "id\n1\n"),
        'mapping' => json_encode(['id' => 'id']),
    ])->assertForbidden();

    $this->getJson('/datagrid/plain/schema')
        ->assertOk()
        ->assertJsonPath('features.export', false)
        ->assertJsonPath('features.import', false);

    $this->postJson('/datagrid/plain/export', ['columns' => ['name']])->assertForbidden();
    $this->post('/datagrid/plain/import', [
        'file' => UploadedFile::fake()->createWithContent('users.csv', "id\n1\n"),
        'mapping' => json_encode(['id' => 'id']),
    ])->assertForbidden();
});

it('lets only an authorized actor edit a column that sets canEdit', function () {
    $auth = app(AuthManager::class);
    $grid = new UserGrid;
    $open = Column::make('name')->editable('required|string');
    $locked = Column::make('email')->editable('required|email')->canEdit(fn ($actor) => $actor === 'editor');

    expect($grid->canEditColumn($open, 'guest', $auth))->toBeTrue()
        ->and($grid->canEditColumn($locked, 'editor', $auth))->toBeTrue()
        ->and($grid->canEditColumn($locked, 'guest', $auth))->toBeFalse();

    config([
        'datagrid.grids' => ['guarded' => GuardedEditGrid::class],
        'datagrid.authorization.actor_resolver' => HeaderActorResolver::class,
    ]);

    $alice = User::create(['name' => 'Alice', 'email' => 'alice@x.test']);

    $guest = $this->getJson('/datagrid/guarded/schema')->assertOk();
    expect(collect($guest->json('columns'))->firstWhere('key', 'email')['editable'])->toBeFalse()
        ->and(collect($guest->json('columns'))->firstWhere('key', 'name')['editable'])->toBeTrue();

    $editor = $this->getJson('/datagrid/guarded/schema', ['X-Actor' => 'editor'])->assertOk();
    expect(collect($editor->json('columns'))->firstWhere('key', 'email')['editable'])->toBeTrue();

    $this->patchJson('/datagrid/guarded/rows/'.$alice->id, ['field' => 'email', 'value' => 'ada@x.test'])
        ->assertStatus(422);

    $this->patchJson('/datagrid/guarded/rows/'.$alice->id, ['field' => 'email', 'value' => 'ada@x.test'], ['X-Actor' => 'editor'])
        ->assertOk()
        ->assertJsonPath('value', 'ada@x.test');
});
