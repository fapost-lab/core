<?php

declare(strict_types=1);

namespace App\Http\DataTable;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The server side of a list screen: search, sort and pagination read from the query string, applied to an
 * Eloquent query and returned as the props the kit's `DataTable` component renders.
 *
 * The query string is user input, so it only ever selects among what the screen declared: sortable and searchable
 * columns are whitelists, and anything else (an unknown column, a page size outside the options) falls back to the
 * defaults instead of failing.
 *
 * - `search`   : text matched case-insensitively against any searchable column;
 * - `sort`     : a sortable column, `-` in front for descending;
 * - `per_page` : one of the page size options;
 * - `page`     : the page number, 1-based;
 * - `filter`   : `filter[key]=value` for a filter the screen declared;
 * - `group`    : the key of a grouping the screen declared, which gathers the rows before the sort orders them.
 *
 * Filters and groupings are optional and domain-neutral: the screen hands in the closures that narrow or order its own
 * query, the table only decides whether the request names one and what to echo back. A table that declares neither
 * has no `filters` or `group` in its `state` and `defaults`, so the shape of the screens that do not use them stays.
 *
 * Typical use, in a controller: `(new DataTable(sortable: ['name'], searchable: ['name'], defaultSort: 'name'))
 * ->respond($request, $query, fn (Group $group): array => [...])`.
 */
final readonly class DataTable
{
    public const int MAX_SEARCH_LENGTH = 100;

    /**
     * @param  list<string>  $sortable       columns the list may be sorted by (a column, or an alias such as a `withCount`)
     * @param  list<string>  $searchable     columns the search text is matched against
     * @param  string        $defaultSort    `column` or `-column`, used when the request names no valid sort
     * @param  list<int>     $perPageOptions the first one is the default page size
     * @param  array<string, Closure(Builder<covariant Model>, string): bool>  $filters  by key; gets the query and the
     *         trimmed value from `filter[key]` and returns whether it narrowed the query (a value it does not accept
     *         is no filter and is not echoed back)
     * @param  array<string, Closure(Builder<covariant Model>): void>  $groups  by key; orders the query so a group's
     *         rows sit together, applied before the sort
     * @param  (Closure(Builder<covariant Model>, string): void)|null  $searchAlso  adds its own `orWhere` to the search
     *         group, for a match the LIKE on a column cannot express (an exact id); gets the trimmed search text
     */
    public function __construct(
        private array $sortable,
        private array $searchable,
        private string $defaultSort,
        private array $perPageOptions = [25, 50, 100],
        private array $filters = [],
        private array $groups = [],
        private ?Closure $searchAlso = null,
    ) {
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>                        $query
     * @param  Closure(TModel): array<string, mixed>  $map   turns a record into its row
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     meta: array{total: int, perPage: int, currentPage: int, lastPage: int, from: int|null, to: int|null},
     *     state: array{search: string, sort: string, perPage: int, filters?: array<string, string>, group?: string},
     *     defaults: array{sort: string, perPage: int, perPageOptions: list<int>, group?: string}
     * }
     */
    public function respond(Request $request, Builder $query, Closure $map): array
    {
        $search  = $this->search($request);
        $sort    = $this->sort($request);
        $perPage = $this->perPage($request);

        if ('' !== $search) {
            $this->applySearch($query, $search);
        }

        $appliedFilters = $this->applyFilters($request, $query);
        $group          = $this->applyGroup($request, $query);

        $this->applySort($query, $sort);

        $page      = max(1, (int) $request->query('page', 1));
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // The page asked for is gone (the last rows of the last page were deleted): show the last one instead.
        if ($paginator->currentPage() > $paginator->lastPage() && $paginator->lastPage() > 0) {
            $paginator = $query->paginate($perPage, ['*'], 'page', $paginator->lastPage());
        }

        return [
            'rows' => $paginator->getCollection()->map($map)->values()->all(),
            'meta' => [
                'total'       => $paginator->total(),
                'perPage'     => $paginator->perPage(),
                'currentPage' => $paginator->currentPage(),
                'lastPage'    => $paginator->lastPage(),
                'from'        => $paginator->firstItem(),
                'to'          => $paginator->lastItem(),
            ],
            'state' => [
                'search'  => $search,
                'sort'    => $sort,
                'perPage' => $perPage,
                // Only the filters that narrowed the query; `[]` when none did.
                ...([] === $this->filters ? [] : ['filters' => $appliedFilters]),
                ...([] === $this->groups ? [] : ['group' => $group]),
            ],
            // What the query string leaves out, so the client does not repeat the screen's choices.
            'defaults' => [
                'sort'           => $this->defaultSort,
                'perPage'        => $this->perPageOptions[0],
                'perPageOptions' => $this->perPageOptions,
                ...([] === $this->groups ? [] : ['group' => '']),
            ],
        ];
    }

    /**
     * The column a `sort` value names: at most one leading `-` marks the direction.
     */
    private static function column(string $sort): string
    {
        return str_starts_with($sort, '-') ? mb_substr($sort, 1) : $sort;
    }

    private function search(Request $request): string
    {
        $value = $request->query('search');

        return is_string($value) ? mb_substr(mb_trim($value), 0, self::MAX_SEARCH_LENGTH) : '';
    }

    private function sort(Request $request): string
    {
        $value = $request->query('sort');

        if (is_string($value) && in_array(self::column($value), $this->sortable, true)) {
            return $value;
        }

        return $this->defaultSort;
    }

    /**
     * Applies the declared filters the request names with a usable value.
     *
     * @param  Builder<covariant Model>  $query
     *
     * @return array<string, string> the filters that narrowed the query, by key
     */
    private function applyFilters(Request $request, Builder $query): array
    {
        $requested = $request->query('filter');
        $applied   = [];

        if ([] === $this->filters || ! is_array($requested)) {
            return $applied;
        }

        foreach ($this->filters as $key => $filter) {
            $value = $requested[$key] ?? null;
            $value = is_string($value) ? mb_substr(mb_trim($value), 0, self::MAX_SEARCH_LENGTH) : '';

            if ('' !== $value && $filter($query, $value)) {
                $applied[$key] = $value;
            }
        }

        return $applied;
    }

    /**
     * Applies the declared grouping the request names, if any.
     *
     * @param  Builder<covariant Model>  $query
     *
     * @return string the key applied, or '' for none
     */
    private function applyGroup(Request $request, Builder $query): string
    {
        $key = $request->query('group');

        if (! is_string($key) || ! isset($this->groups[$key])) {
            return '';
        }

        $this->groups[$key]($query);

        return $key;
    }

    private function perPage(Request $request): int
    {
        $value = (int) $request->query('per_page');

        return in_array($value, $this->perPageOptions, true) ? $value : $this->perPageOptions[0];
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        $grammar = $query->getQuery()->getGrammar();
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)) . '%';

        $query->where(function (Builder $group) use ($grammar, $pattern, $search): void {
            foreach ($this->searchable as $column) {
                // LOWER() + LIKE rather than ILIKE, so one statement runs on both engines. Postgres lowercases any
                // Unicode text; SQLite's LOWER() only touches ASCII, which matters for tests, not for production.
                $group->orWhereRaw('LOWER(' . $grammar->wrap($column) . ") LIKE ? ESCAPE '!'", [$pattern]);
            }

            if (null !== $this->searchAlso) {
                ($this->searchAlso)($group, $search);
            }
        });
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        $column    = self::column($sort);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        $query->orderBy($column, $direction);

        // A stable order across pages when the sorted values repeat.
        $query->orderBy($query->getModel()->getQualifiedKeyName());
    }
}
