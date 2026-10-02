<?php

namespace CodeCorner\Datagrid\Auth;

use Closure;

class CallbackAuthorizer implements Authorizer
{
    public function __construct(private Closure $callback) {}

    public function allows(mixed $actor, string $ability, AuthContext $context): bool
    {
        return (bool) ($this->callback)($actor, $ability, $context);
    }
}
