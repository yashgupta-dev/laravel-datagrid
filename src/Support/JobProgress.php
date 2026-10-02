<?php

namespace CodeCorner\Datagrid\Support;

use Illuminate\Support\Facades\Cache;

final class JobProgress
{
    public static function key(string $id): string
    {
        return 'datagrid-job:'.$id;
    }

    /** @param array<string, mixed> $extra */
    public static function start(string $id, string $actorKey, int $total = 0, array $extra = []): void
    {
        Cache::put(self::key($id), array_merge([
            'status' => 'queued',
            'done' => 0,
            'total' => $total,
            'message' => '',
            'actor' => $actorKey,
            'download' => null,
            'file' => null,
        ], $extra), now()->addHours(2));
    }

    /** @param array<string, mixed> $patch */
    public static function update(string $id, array $patch): void
    {
        $current = Cache::get(self::key($id), []);
        if (! is_array($current)) {
            $current = [];
        }
        Cache::put(self::key($id), array_merge($current, $patch), now()->addHours(2));
    }

    /** @return array<string, mixed>|null */
    public static function get(string $id): ?array
    {
        $row = Cache::get(self::key($id));

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row */
    public static function public(array $row): array
    {
        return [
            'status' => $row['status'] ?? 'queued',
            'done' => (int) ($row['done'] ?? 0),
            'total' => (int) ($row['total'] ?? 0),
            'message' => (string) ($row['message'] ?? ''),
            'download' => $row['download'] ?? null,
        ];
    }
}
