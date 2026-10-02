<?php

namespace CodeCorner\Datagrid\Core\Query;

final class DataRequest
{
    /**
     * @param  list<array{field: string, dir: string}>  $sort
     * @param  list<string>  $columns
     */
    public function __construct(
        public readonly int $page = 1,
        public readonly int $perPage = 25,
        public readonly array $sort = [],
        public readonly ?array $filter = null,
        public readonly string $search = '',
        public readonly array $columns = [],
        public readonly bool $includeTotal = true,
    ) {}

    public static function fromArray(array $in, array $limits = []): self
    {
        $maxPer = (int) ($limits['max_per_page'] ?? 200);
        $perPage = max(1, min($maxPer, (int) ($in['perPage'] ?? $limits['default_per_page'] ?? 25)));
        $maxSorts = (int) ($limits['max_sorts'] ?? 3);

        $sort = [];
        foreach (array_slice((array) ($in['sort'] ?? []), 0, $maxSorts) as $s) {
            if (is_array($s) && is_string($s['field'] ?? null)) {
                $sort[] = ['field' => $s['field'], 'dir' => strtolower((string) ($s['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc'];
            }
        }

        $filter = isset($in['filter']) && $in['filter'] !== []
            ? FilterTree::parse($in['filter'], (int) ($limits['max_filter_depth'] ?? 4), (int) ($limits['max_filter_conditions'] ?? 25))
            : null;

        $search = trim((string) ($in['search'] ?? ''));
        $search = mb_substr($search, 0, (int) ($limits['max_search_length'] ?? 100));

        return new self(
            page: max(1, (int) ($in['page'] ?? 1)),
            perPage: $perPage,
            sort: $sort,
            filter: $filter,
            search: $search,
            columns: array_values(array_filter((array) ($in['columns'] ?? []), 'is_string')),
            includeTotal: (bool) ($in['includeTotal'] ?? true),
        );
    }
}
