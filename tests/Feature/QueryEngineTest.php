<?php

use CodeCorner\Datagrid\Auth\AuthManager;
use CodeCorner\Datagrid\Core\Query\DataRequest;
use CodeCorner\Datagrid\Core\Query\QueryEngine;
use CodeCorner\Datagrid\Exceptions\InvalidFilterException;
use CodeCorner\Datagrid\Tests\Fixtures\Post;
use CodeCorner\Datagrid\Tests\Fixtures\Role;
use CodeCorner\Datagrid\Tests\Fixtures\User;
use CodeCorner\Datagrid\Tests\Fixtures\UserGrid;

require_once __DIR__.'/../Fixtures/UserGrid.php';

function runGrid(array $payload, mixed $actor = null): array
{
    $grid = new UserGrid;
    $columns = $grid->allowedColumns($actor, app(AuthManager::class));

    return app(QueryEngine::class)->run($grid, $columns, $actor, DataRequest::fromArray($payload, config('datagrid.limits')));
}

beforeEach(function () {
    $admin = Role::create(['name' => 'Admin']);
    $staff = Role::create(['name' => 'Staff']);

    $a = User::create(['name' => 'Alice', 'email' => 'alice@x.test', 'role_id' => $admin->id, 'salary' => '90']);
    $b = User::create(['name' => 'Bob', 'email' => 'bob@x.test', 'role_id' => $staff->id]);
    User::create(['name' => 'Cara', 'email' => 'cara@x.test']);

    Post::create(['user_id' => $a->id, 'title' => 'Hello', 'views' => 10]);
    Post::create(['user_id' => $a->id, 'title' => 'World', 'views' => 5]);
    Post::create(['user_id' => $b->id, 'title' => 'Hello', 'views' => 1]);
});

it('filters on a joined to-one column and returns it as a value', function () {
    $r = runGrid([
        'columns' => ['name', 'role.name'],
        'filter' => ['logic' => 'and', 'children' => [['field' => 'role.name', 'op' => 'eq', 'value' => 'Admin']]],
    ]);

    expect($r['meta']['total'])->toBe(1)
        ->and($r['data'][0]['values'])->toBe(['name' => 'Alice', 'role.name' => 'Admin']);
});

it('uses EXISTS for to-many filters without duplicating rows', function () {
    $r = runGrid(['filter' => ['field' => 'posts.title', 'op' => 'eq', 'value' => 'Hello']]);

    expect($r['meta']['total'])->toBe(2);
});

it('supports AND/OR groups', function () {
    $r = runGrid(['filter' => ['logic' => 'or', 'children' => [
        ['field' => 'name', 'op' => 'eq', 'value' => 'Bob'],
        ['field' => 'name', 'op' => 'starts_with', 'value' => 'Ca'],
    ]]]);

    expect($r['meta']['total'])->toBe(2);
});

it('searches across direct and joined columns', function () {
    expect(runGrid(['search' => 'staff'])['meta']['total'])->toBe(1)
        ->and(runGrid(['search' => 'alice@'])['meta']['total'])->toBe(1);
});

it('treats LIKE wildcards in user input literally', function () {
    expect(runGrid(['search' => '%'])['meta']['total'])->toBe(0);
});

it('sorts by an aggregate and paginates with a stable order', function () {
    $r = runGrid(['columns' => ['name', 'posts_views'], 'sort' => [['field' => 'posts_views', 'dir' => 'desc']], 'perPage' => 2]);

    expect($r['data'][0]['values']['name'])->toBe('Alice')
        ->and($r['meta']['lastPage'])->toBe(2)
        ->and($r['meta']['hasMore'])->toBeTrue();
});

it('can skip the total count and still report hasMore', function () {
    $r = runGrid(['perPage' => 2, 'includeTotal' => false]);

    expect($r['meta']['total'])->toBeNull()
        ->and($r['data'])->toHaveCount(2)
        ->and($r['meta']['hasMore'])->toBeTrue();
});

it('rejects filtering on unknown or non-filterable fields', function () {
    runGrid(['filter' => ['field' => 'password', 'op' => 'eq', 'value' => 'x']]);
})->throws(InvalidFilterException::class);

it('rejects operators not allowed for the field', function () {
    runGrid(['filter' => ['field' => 'name', 'op' => 'gt', 'value' => 'x']]);
})->throws(InvalidFilterException::class);

it('enforces column permissions on filter and output', function () {
    // salary has no filter, but it must also be absent for non-HR actors.
    $columns = (new UserGrid)->allowedColumns('guest', app(AuthManager::class));
    expect($columns)->not->toHaveKey('salary');

    $columns = (new UserGrid)->allowedColumns('hr', app(AuthManager::class));
    expect($columns)->toHaveKey('salary');
});

it('limits filter depth', function () {
    $node = ['field' => 'name', 'op' => 'eq', 'value' => 'a'];
    for ($i = 0; $i < 6; $i++) {
        $node = ['logic' => 'and', 'children' => [$node]];
    }
    runGrid(['filter' => $node]);
})->throws(InvalidFilterException::class);
