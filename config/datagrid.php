<?php

return [

    /*
    | Grids exposed over HTTP. Either ['users' => UserGrid::class] or a plain
    | list of classes (the key then comes from Grid::key()).
    */
    'grids' => [],

    'routes' => [
        'enabled' => true,
        'prefix' => 'datagrid',
        // Add your own auth middleware here (e.g. 'auth:sanctum').
        'middleware' => ['api'],
    ],

    /*
    | Authorization. The grid only asks "may this actor do this ability?".
    | Drivers: none | gate | callback | custom
    */
    'authorization' => [
        'driver' => 'none',
        'guard' => null,
        'callback' => null,     // Closure(mixed $actor, string $ability, AuthContext $ctx): bool
        'authorizer' => null,   // class-string<Authorizer> when driver = custom
        'actor_resolver' => null, // class-string<ActorResolver>, default uses the auth guard
        // When a column has no ->can(), derive "{grid}.{column}.view" (ignored for driver=none).
        'conventions' => false,
    ],

    'limits' => [
        'default_per_page' => 25,
        'max_per_page' => 200,
        'max_filter_depth' => 4,
        'max_filter_conditions' => 25,
        'max_sorts' => 3,
        'max_in_values' => 500,
        'max_search_length' => 100,
        'max_action_ids' => 500,
    ],

    /*
    | Page results are cached for this many seconds. Mutations bump a
    | generation key so the next read is fresh. Set ttl to 0 to disable.
    */
    'cache' => [
        'ttl' => 30,
    ],
];
