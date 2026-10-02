<?php

namespace CodeCorner\Datagrid\Auth;

use Illuminate\Support\Facades\Gate;

class GateAuthorizer implements Authorizer
{
    public function allows(mixed $actor, string $ability, AuthContext $context): bool
    {
        // Undefined abilities are denied by the Gate, which is the safe default.
        return Gate::forUser($actor)->allows($ability, [$context]);
    }
}
