<?php

namespace CodeCorner\Datagrid\Support;

use Illuminate\Support\Facades\Route;
use Throwable;

final class RouteLink
{
    /**
     * Resolve a named route. A parameter value of "{id}" is left for the client
     * to replace with the row primary key.
     *
     * @param  array<string, mixed>  $params
     */
    public static function href(?string $name, array $params = []): ?string
    {
        if ($name === null || $name === '' || ! Route::has($name)) {
            return null;
        }

        $resolved = [];
        foreach ($params as $key => $value) {
            $resolved[$key] = $value === '{id}' ? '000000' : $value;
        }

        try {
            $url = route($name, $resolved);
        } catch (Throwable) {
            return null;
        }

        return str_replace('000000', '{id}', $url);
    }
}
