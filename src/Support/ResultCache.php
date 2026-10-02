<?php

namespace CodeCorner\Datagrid\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Short-lived cache for schema, row counts, and page payloads.
 * A generation key is bumped on writes so the next read is fresh.
 * A cache-store failure falls through to the live query.
 */
final class ResultCache
{
    private static ?string $active = null;

    public static function using(string $grid, Closure $callback): mixed
    {
        $previous = self::$active;
        self::$active = $grid;
        try {
            return $callback();
        } finally {
            self::$active = $previous;
        }
    }

    public static function activeGrid(string $fallback): string
    {
        return self::$active ?? $fallback;
    }

    public static function actorKey(mixed $actor): string
    {
        if (is_object($actor) && method_exists($actor, 'getAuthIdentifier')) {
            $id = $actor->getAuthIdentifier();

            return $id === null ? '' : (string) $id;
        }

        return is_scalar($actor) ? (string) $actor : '';
    }

    public static function remember(string $grid, string $actorKey, array $payload, Closure $callback, string $bucket = 'data'): mixed
    {
        $ttl = (int) config('datagrid.cache.ttl', 30);
        if ($ttl <= 0) {
            return $callback();
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        try {
            $gen = (int) Cache::get(self::genKey($grid), 1);
            $key = 'datagrid-'.$bucket.':'.hash('sha256', $gen.'|'.$grid.'|'.$actorKey.'|'.$encoded);
            $cached = Cache::get($key);
        } catch (Throwable) {
            return $callback();
        }

        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();
        try {
            Cache::put($key, $value, now()->addSeconds($ttl));
        } catch (Throwable) {
            // The live result is still returned when the store cannot be written.
        }

        return $value;
    }

    public static function bump(string $grid): void
    {
        try {
            $key = self::genKey($grid);
            Cache::forever($key, ((int) Cache::get($key, 1)) + 1);
        } catch (Throwable) {
            // A missing cache store must not fail the write that triggered the bump.
        }
    }

    private static function genKey(string $grid): string
    {
        return 'datagrid-gen:'.$grid;
    }
}
