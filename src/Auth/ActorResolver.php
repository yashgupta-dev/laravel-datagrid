<?php

namespace CodeCorner\Datagrid\Auth;

use Illuminate\Http\Request;

interface ActorResolver
{
    /** Resolve the acting user/client for a request. May return null. */
    public function resolve(Request $request): mixed;
}
