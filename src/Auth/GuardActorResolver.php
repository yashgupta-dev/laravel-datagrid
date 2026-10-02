<?php

namespace CodeCorner\Datagrid\Auth;

use Illuminate\Http\Request;

class GuardActorResolver implements ActorResolver
{
    public function __construct(private ?string $guard = null) {}

    public function resolve(Request $request): mixed
    {
        return auth($this->guard)->user();
    }
}
