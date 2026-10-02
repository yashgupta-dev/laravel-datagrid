<?php

namespace CodeCorner\Datagrid\Support;

final class EditRules
{
    /**
     * Append the current primary key to string `unique:` rules so an inline
     * update does not collide with the row being edited.
     *
     * @param  string|array<int, mixed>  $rules
     * @return array<int, mixed>
     */
    public static function ignoringCurrent(string|array $rules, mixed $id, string $keyName): array
    {
        $list = is_string($rules) ? explode('|', $rules) : $rules;

        return array_map(function (mixed $rule) use ($id, $keyName) {
            if (! is_string($rule) || ! str_starts_with($rule, 'unique:')) {
                return $rule;
            }

            $parts = explode(',', substr($rule, 7));
            if (count($parts) >= 3 && $parts[2] !== '') {
                return $rule;
            }

            $parts[1] = $parts[1] ?? '';
            $parts[2] = (string) $id;
            $parts[3] = $keyName;

            return 'unique:'.implode(',', $parts);
        }, $list);
    }
}
