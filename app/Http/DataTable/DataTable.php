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
 * - `page`     : the page number, 1-based.
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
     */
    public function __construct(
        private array $sortable,
        private array $searchable,
        private string $defaultSort,
        private array $perPageOptions = [25, 50, 100],
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
     *     state: array{search: string, sort: string, perPage: int},
     *     defaults: array{sort: string, perPage: int, perPageOptions: list<int>}
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
            'state' => ['search' => $search, 'sort' => $sort, 'perPage' => $perPage],
            // What the query string leaves out, so the client does not repeat the screen's choices.
            'defaults' => ['sort' => $this->defaultSort, 'perPage' => $this->perPageOptions[0], 'perPageOptions' => $this->perPageOptions],
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

        $query->where(function (Builder $group) use ($grammar, $pattern): void {
            foreach ($this->searchable as $column) {
                // LOWER() + LIKE rather than ILIKE, so one statement runs on both engines. Postgres lowercases any
                // Unicode text; SQLite's LOWER() only touches ASCII, which matters for tests, not for production.
                $group->orWhereRaw('LOWER(' . $grammar->wrap($column) . ") LIKE ? ESCAPE '!'", [$pattern]);
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
