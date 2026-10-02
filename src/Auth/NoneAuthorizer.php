<?php

namespace CodeCorner\Datagrid\Auth;

class NoneAuthorizer implements Authorizer
{
    public function allows(mixed $actor, string $ability, AuthContext $context): bool
    {
        return true;
    }
}
