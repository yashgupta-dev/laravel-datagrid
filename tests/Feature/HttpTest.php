<?php

use CodeCorner\Datagrid\Tests\Fixtures\ScopedUserGrid;
use CodeCorner\Datagrid\Tests\Fixtures\User;
use CodeCorner\Datagrid\Tests\Fixtures\UserGrid;

require_once __DIR__.'/../Fixtures/UserGrid.php';
require_once __DIR__.'/../Fixtures/ScopedUserGrid.php';

beforeEach(function () {
    UserGrid::$ran = [];
    config(['datagrid.grids' => ['users' => UserGrid::class]]);
});

it('serves the schema', function () {
    $this->getJson('/datagrid/users/schema')
        ->assertOk()
        ->assertJsonPath('schemaVersion', 1)
        ->assertJsonPath('actions.0.key', 'touch')
        ->assertJsonPath('actions.0.row', true)
        ->assertJsonPath('actions.0.bulk', true)
        ->assertJsonPath('columns.0.copy', true)
        ->assertJsonPath('columns.0.editable', true)
        ->assertJsonPath('limits.maxActionIds', 500)
        ->assertJsonMissing(['key' => 'salary'])
        ->assertJsonMissing(['key' => 'purge']);
});

it('serves data and returns 422 for invalid filters', function () {
    $this->postJson('/datagrid/users/data', ['perPage' => 5])->assertOk()->assertJsonStructure(['data', 'meta']);

    $this->postJson('/datagrid/users/data', ['filter' => ['field' => 'nope', 'op' => 'eq', 'value' => 1]])
        ->assertStatus(422);
});

it('404s for unknown grids', function () {
    $this->getJson('/datagrid/missing/schema')->assertNotFound();
});

it('runs a row action and a bulk action', function () {
    $alice = User::create(['name' => 'Alice', 'email' => 'alice@x.test']);
    $bob = User::create(['name' => 'Bob', 'email' => 'bob@x.test']);

    $this->postJson('/datagrid/users/actions/touch', ['ids' => [$alice->id]])
        ->assertOk()
        ->assertJsonPath('affected', 1)
        ->assertJsonPath('message', 'Touched 1');

    expect(UserGrid::$ran)->toBe([$alice->id]);

    $queued = $this->postJson('/datagrid/users/actions/touch', [
        'ids' => [$alice->id, $bob->id],
        'bulk' => true,
    ])->assertOk()->assertJsonPath('status', 'queued');

    $this->getJson('/datagrid/users/jobs/'.$queued->json('job'), ['X-Datagrid-Token' => $queued->json('token')])
        ->assertOk()
        ->assertJsonPath('status', 'done')
        ->assertJsonPath('done', 2)
        ->assertJsonPath('message', 'Touched 2');

    expect(UserGrid::$ran)->toEqualCanonicalizing([$alice->id, $bob->id]);
});

it('404s for an unknown action', function () {
    $alice = User::create(['name' => 'Alice', 'email' => 'alice@x.test']);

    $this->postJson('/datagrid/users/actions/missing', ['ids' => [$alice->id]])->assertNotFound();
    expect(UserGrid::$ran)->toBe([]);
});

it('403s for a forbidden action and does not run it', function () {
    $alice = User::create(['name' => 'Alice', 'email' => 'alice@x.test']);

    $this->postJson('/datagrid/users/actions/purge', ['ids' => [$alice->id]])->assertForbidden();
    expect(UserGrid::$ran)->toBe([]);
});

it('422s when an id is outside the grid scope and does not run the handler', function () {
    config(['datagrid.grids' => ['scoped' => ScopedUserGrid::class]]);

    $alice = User::create(['name' => 'Alice', 'email' => 'alice@x.test']);
    $bob = User::create(['name' => 'Bob', 'email' => 'bob@x.test']);

    $this->postJson('/datagrid/scoped/actions/touch', ['ids' => [$bob->id]])
        ->assertStatus(422);

    $this->postJson('/datagrid/scoped/actions/touch', ['ids' => [$alice->id, $bob->id]])
        ->assertStatus(422);

    expect(UserGrid::$ran)->toBe([]);
    expect(User::query()->count())->toBe(2);
});
