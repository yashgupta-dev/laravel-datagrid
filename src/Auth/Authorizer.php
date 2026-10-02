<?php

namespace CodeCorner\Datagrid\Auth;

interface Authorizer
{
    /**
     * The only question the grid ever asks: may this actor do this ability here?
     * $actor is whatever the ActorResolver returned (a User, an API client, null...).
     */
    public function allows(mixed $actor, string $ability, AuthContext $context): bool;
}
