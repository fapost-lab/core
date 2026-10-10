<?php

declare(strict_types=1);

namespace App\Http\DataTable;

use Closure;
use Illuminate\Http\Request;

/**
 * {@see DataTable} for rows that are not a database table (the system translation catalog, built in memory): the
 * same query string, the same whitelists and fallbacks, the same props for the kit's `DataTable` component, applied to
 * a list of arrays instead of an Eloquent query.
 *
 * - `search`   : text matched case-insensitively, as a literal, against any searchable key of a row;
 * - `sort`     : a sortable key, `-` in front for descending; ties keep the order the rows came in;
 * - `per_page` : one of the page size options;
 * - `page`     : the page number, 1-based; past the end shows the last page;
 * - `filter`   : `filter[key]=value`, kept only when the value is one the screen declared for that key, and then
 *                matched exactly against the row's value.
 *
 * Keep it to lists that fit in memory: every request builds and scans all rows.
 */
final readonly class ArrayDataTable
{
    /**
     * @param  list<string>                $sortable       row keys the list may be sorted by
     * @param  list<string>                $searchable     row keys the search text is matched against
     * @param  string                      $defaultSort    `key` or `-key`, used when the request names no valid sort
     * @param  list<int>                   $perPageOptions the first one is the default page size
     * @param  array<string, list<string>> $filters        by row key, the values a filter accepts
     */
    public function __construct(
        private array $sortable,
        private array $searchable,
        private string $defaultSort,
        private array $perPageOptions = [25, 50, 100],
        private array $filters = [],
    ) {
    }

    /**
     * @template TRow of array<string, mixed>
     *
     * @param  list<TRow>                          $rows
     * @param  Closure(TRow): array<string, mixed> $map  turns a row of the page into what the client gets
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     meta: array{total: int, perPage: int, currentPage: int, lastPage: int, from: int|null, to: int|null},
     *     state: array{search: string, sort: string, perPage: int, filters?: array<string, string>},
     *     defaults: array{sort: string, perPage: int, perPageOptions: list<int>}
     * }
     */
    public function respond(Request $request, array $rows, Closure $map): array
    {
        $search  = $this->search($request);
        $sort    = $this->sort($request);
        $perPage = $this->perPage($request);
        $filters = $this->requestedFilters($request);

        $rows = array_values(array_filter($rows, fn (array $row): bool => $this->matches($row, $search, $filters)));
        $rows = $this->sorted($rows, $sort);

        $total    = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page     = min(max(1, (int) $request->query('page', 1)), $lastPage);
        $slice    = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'rows' => array_map($map, $slice),
            'meta' => [
                'total'       => $total,
                'perPage'     => $perPage,
                'currentPage' => $page,
                'lastPage'    => $lastPage,
                'from'        => [] === $slice ? null : ($page - 1) * $perPage + 1,
                'to'          => [] === $slice ? null : ($page - 1) * $perPage + count($slice),
            ],
            'state' => [
                'search'  => $search,
                'sort'    => $sort,
                'perPage' => $perPage,
                ...([] === $this->filters ? [] : ['filters' => $filters]),
            ],
            'defaults' => [
                'sort'           => $this->defaultSort,
                'perPage'        => $this->perPageOptions[0],
                'perPageOptions' => $this->perPageOptions,
            ],
        ];
    }

    private static function column(string $sort): string
    {
        return str_starts_with($sort, '-') ? mb_substr($sort, 1) : $sort;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function search(Request $request): string
    {
        $value = $request->query('search');

        return is_string($value) ? mb_substr(mb_trim($value), 0, DataTable::MAX_SEARCH_LENGTH) : '';
    }

    private function sort(Request $request): string
    {
        $value = $request->query('sort');

        if (is_string($value) && in_array(self::column($value), $this->sortable, true)) {
            return $value;
        }

        return $this->defaultSort;
    }

    private function perPage(Request $request): int
    {
        $value = (int) $request->query('per_page');

        return in_array($value, $this->perPageOptions, true) ? $value : $this->perPageOptions[0];
    }

    /**
     * The declared filters the request names with a value the filter accepts.
     *
     * @return array<string, string>
     */
    private function requestedFilters(Request $request): array
    {
        $requested = $request->query('filter');
        $applied   = [];

        if (! is_array($requested)) {
            return $applied;
        }

        foreach ($this->filters as $key => $accepted) {
            $value = $requested[$key] ?? null;

            if (is_string($value) && in_array($value, $accepted, true)) {
                $applied[$key] = $value;
            }
        }

        return $applied;
    }

    /**
     * @param  array<string, mixed>   $row
     * @param  array<string, string>  $filters
     */
    private function matches(array $row, string $search, array $filters): bool
    {
        foreach ($filters as $key => $value) {
            if (self::text($row[$key] ?? null) !== $value) {
                return false;
            }
        }

        if ('' === $search) {
            return true;
        }

        foreach ($this->searchable as $key) {
            if (false !== mb_stripos(self::text($row[$key] ?? null), $search)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template TRow of array<string, mixed>
     *
     * @param  list<TRow>  $rows
     *
     * @return list<TRow>
     */
    private function sorted(array $rows, string $sort): array
    {
        $column    = self::column($sort);
        $direction = str_starts_with($sort, '-') ? -1 : 1;

        // usort is stable since PHP 8.0, so equal values keep the order the rows came in.
        usort($rows, static fn (array $a, array $b): int => $direction * strnatcasecmp(self::text($a[$column] ?? null), self::text($b[$column] ?? null)));

        return $rows;
    }
}
