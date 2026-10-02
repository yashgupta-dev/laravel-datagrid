<?php

use CodeCorner\Datagrid\Core\Operators\OperatorRegistry;
use CodeCorner\Datagrid\Exceptions\InvalidFilterException;
use CodeCorner\Datagrid\Support\Like;

it('escapes LIKE wildcards', function () {
    expect(Like::pattern('50%_!'))->toBe('%50!%!_!!%');
});

it('knows built-in operators and rejects unknown ones', function () {
    $r = new OperatorRegistry;
    expect($r->has('contains'))->toBeTrue();
    $r->get('drop_table');
})->throws(InvalidFilterException::class);
