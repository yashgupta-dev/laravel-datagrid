<?php

namespace CodeCorner\Datagrid\Core\Query;

use CodeCorner\Datagrid\Exceptions\InvalidFilterException;

final class FilterTree
{
    /**
     * Validate and normalize a filter payload.
     *
     * Group:     {"logic": "and"|"or", "children": [...]}
     * Condition: {"field": "status", "op": "in", "value": [...]}
     */
    public static function parse(mixed $node, int $maxDepth, int $maxConditions): array
    {
        $count = 0;

        $walk = function (mixed $n, int $depth) use (&$walk, &$count, $maxDepth, $maxConditions): array {
            if (! is_array($n)) {
                throw new InvalidFilterException('Filter node must be an object.');
            }
            if ($depth > $maxDepth) {
                throw new InvalidFilterException("Filter nesting exceeds the maximum depth of {$maxDepth}.");
            }

            if (array_key_exists('children', $n)) {
                $logic = strtolower((string) ($n['logic'] ?? 'and'));
                if (! in_array($logic, ['and', 'or'], true)) {
                    throw new InvalidFilterException('Filter logic must be "and" or "or".');
                }
                if (! is_array($n['children'])) {
                    throw new InvalidFilterException('Filter children must be a list.');
                }

                return [
                    'logic' => $logic,
                    'children' => array_map(fn ($c) => $walk($c, $depth + 1), array_values($n['children'])),
                ];
            }

            if (! is_string($n['field'] ?? null) || ! is_string($n['op'] ?? null)) {
                throw new InvalidFilterException('A filter condition needs string "field" and "op".');
            }
            if (++$count > $maxConditions) {
                throw new InvalidFilterException("Filter has more than {$maxConditions} conditions.");
            }

            return ['field' => $n['field'], 'op' => $n['op'], 'value' => $n['value'] ?? null];
        };

        return $walk($node, 1);
    }
}
