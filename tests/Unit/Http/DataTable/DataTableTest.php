<?php

declare(strict_types=1);

namespace Tests\Unit\Http\DataTable;

use App\Http\DataTable\DataTable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use PHPUnit\Framework\TestCase;

/**
 * The list state the DataTable reads from the query string: only what the screen declared gets through.
 * Runs on a private in-memory SQLite, the same engine the feature tests use.
 */
final class DataTableTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        // The paginator asks the container for the request unless told otherwise, and after a test that booted
        // Laravel the container held by `Container::getInstance()` is a stale one.
        Paginator::currentPathResolver(static fn (): string => '/');
        Paginator::currentPageResolver(static fn (): int => 1);

        $this->capsule = new Capsule();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->bootEloquent();
        $this->capsule->setAsGlobal();

        $this->capsule->schema()->create('things', function ($table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('secret')->default('');
        });

        foreach (['Banana', 'Apple', 'Cherry', '50% off', 'A_b', 'Date'] as $name) {
            Thing::query()->create(['name' => $name, 'secret' => 'z-' . $name]);
        }
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();

        parent::tearDown();
    }

    public function test_it_applies_the_default_sort_and_page_size_without_a_query(): void
    {
        $result = $this->table()->respond($this->request([]), Thing::query(), $this->row(...));

        $this->assertSame(['50% off', 'A_b', 'Apple', 'Banana', 'Cherry', 'Date'], array_column($result['rows'], 'name'));
        $this->assertSame(['search' => '', 'sort' => 'name', 'perPage' => 25], $result['state']);
        $this->assertSame(['total' => 6, 'perPage' => 25, 'currentPage' => 1, 'lastPage' => 1, 'from' => 1, 'to' => 6], $result['meta']);
        $this->assertSame(['sort' => 'name', 'perPage' => 25, 'perPageOptions' => [25, 50, 100]], $result['defaults']);
    }

    public function test_it_sorts_descending_with_a_minus(): void
    {
        $result = $this->table()->respond($this->request(['sort' => '-name']), Thing::query(), $this->row(...));

        $this->assertSame('Date', $result['rows'][0]['name']);
        $this->assertSame('-name', $result['state']['sort']);
    }

    public function test_a_column_outside_the_whitelist_falls_back_to_the_default_sort(): void
    {
        foreach (['secret', '-secret', 'name; drop table things', '', '--name', ['name']] as $sort) {
            $result = $this->table()->respond($this->request(['sort' => $sort]), Thing::query(), $this->row(...));

            $this->assertSame('name', $result['state']['sort'], json_encode($sort));
        }
    }

    public function test_a_page_size_outside_the_options_falls_back_to_the_first(): void
    {
        foreach (['7', '0', '-25', 'all', '1000'] as $perPage) {
            $result = $this->table()->respond($this->request(['per_page' => $perPage]), Thing::query(), $this->row(...));

            $this->assertSame(25, $result['state']['perPage'], $perPage);
        }

        $result = $this->table(perPageOptions: [2, 4])->respond($this->request(['per_page' => '4']), Thing::query(), $this->row(...));

        $this->assertSame(4, $result['meta']['perPage']);
        $this->assertCount(4, $result['rows']);
    }

    public function test_it_pages(): void
    {
        $table = $this->table(perPageOptions: [4, 8]);

        $result = $table->respond($this->request(['page' => '2']), Thing::query(), $this->row(...));

        $this->assertSame(['Cherry', 'Date'], array_column($result['rows'], 'name'));
        $this->assertSame(['total' => 6, 'perPage' => 4, 'currentPage' => 2, 'lastPage' => 2, 'from' => 5, 'to' => 6], $result['meta']);
    }

    public function test_a_page_past_the_end_shows_the_last_page_and_a_nonsense_page_the_first(): void
    {
        $table = $this->table(perPageOptions: [4, 8]);

        $this->assertSame(2, $table->respond($this->request(['page' => '99']), Thing::query(), $this->row(...))['meta']['currentPage']);
        $this->assertSame(1, $table->respond($this->request(['page' => '-3']), Thing::query(), $this->row(...))['meta']['currentPage']);
        $this->assertSame(1, $table->respond($this->request(['page' => 'x']), Thing::query(), $this->row(...))['meta']['currentPage']);
    }

    public function test_it_searches_case_insensitively_in_the_searchable_columns_only(): void
    {
        $result = $this->table()->respond($this->request(['search' => 'APP']), Thing::query(), $this->row(...));

        $this->assertSame(['Apple'], array_column($result['rows'], 'name'));
        $this->assertSame('APP', $result['state']['search']);

        // `secret` holds "z-..." for every row, but is not searchable.
        $this->assertSame(0, $this->table()->respond($this->request(['search' => 'z-']), Thing::query(), $this->row(...))['meta']['total']);
    }

    public function test_like_wildcards_in_the_search_are_literal(): void
    {
        $percent    = $this->table()->respond($this->request(['search' => '%']), Thing::query(), $this->row(...));
        $underscore = $this->table()->respond($this->request(['search' => '_']), Thing::query(), $this->row(...));
        $bang       = $this->table()->respond($this->request(['search' => '!']), Thing::query(), $this->row(...));

        $this->assertSame(['50% off'], array_column($percent['rows'], 'name'));
        $this->assertSame(['A_b'], array_column($underscore['rows'], 'name'));
        $this->assertSame([], $bang['rows']);
    }

    public function test_the_search_is_trimmed_and_a_blank_one_is_no_search(): void
    {
        $this->assertSame('cherry', $this->table()->respond($this->request(['search' => '  cherry ']), Thing::query(), $this->row(...))['state']['search']);
        $this->assertSame(6, $this->table()->respond($this->request(['search' => '   ']), Thing::query(), $this->row(...))['meta']['total']);
        $this->assertSame(6, $this->table()->respond($this->request(['search' => ['x']]), Thing::query(), $this->row(...))['meta']['total']);
    }

    public function test_the_search_combines_with_conditions_already_on_the_query(): void
    {
        $result = $this->table()->respond($this->request(['search' => 'a']), Thing::query()->where('name', '!=', 'Banana'), $this->row(...));

        $this->assertSame(['A_b', 'Apple', 'Date'], array_column($result['rows'], 'name'));
    }

    public function test_a_table_that_declares_no_filters_or_groups_has_neither_in_its_state(): void
    {
        $result = $this->table()->respond($this->request(['filter' => ['starts' => 'a'], 'group' => 'a_first']), Thing::query(), $this->row(...));

        $this->assertSame(6, $result['meta']['total']);
        $this->assertSame(['search' => '', 'sort' => 'name', 'perPage' => 25], $result['state']);
        $this->assertSame(['sort' => 'name', 'perPage' => 25, 'perPageOptions' => [25, 50, 100]], $result['defaults']);
    }

    public function test_a_declared_filter_narrows_the_query_and_is_echoed_back(): void
    {
        $result = $this->filtered()->respond($this->request(['filter' => ['starts' => ' a ']]), Thing::query(), $this->row(...));

        $this->assertSame(['A_b', 'Apple'], array_column($result['rows'], 'name'));
        $this->assertSame(['starts' => 'a'], $result['state']['filters']);
        $this->assertSame('', $result['state']['group']);
        $this->assertSame('', $result['defaults']['group']);
    }

    public function test_a_filter_the_screen_declined_or_never_declared_is_not_applied(): void
    {
        // `starts` declines anything that is not a single letter; `secret` is not a declared filter.
        foreach ([['starts' => 'abc'], ['starts' => ''], ['starts' => ['a']], ['secret' => 'z-Date'], ['STARTS' => 'a']] as $filter) {
            $result = $this->filtered()->respond($this->request(['filter' => $filter]), Thing::query(), $this->row(...));

            $this->assertSame(6, $result['meta']['total'], json_encode($filter));
            $this->assertSame([], $result['state']['filters'], json_encode($filter));
        }

        $result = $this->filtered()->respond($this->request(['filter' => 'a']), Thing::query(), $this->row(...));

        $this->assertSame(6, $result['meta']['total']);
    }

    public function test_a_declared_group_orders_the_rows_before_the_sort_does(): void
    {
        $result = $this->filtered()->respond($this->request(['group' => 'a_first']), Thing::query(), $this->row(...));

        $this->assertSame(['A_b', 'Apple', '50% off', 'Banana', 'Cherry', 'Date'], array_column($result['rows'], 'name'));
        $this->assertSame('a_first', $result['state']['group']);

        $result = $this->filtered()->respond($this->request(['group' => 'a_first', 'sort' => '-name']), Thing::query(), $this->row(...));

        $this->assertSame(['Apple', 'A_b', 'Date', 'Cherry', 'Banana', '50% off'], array_column($result['rows'], 'name'));
    }

    public function test_an_unknown_group_is_no_group(): void
    {
        foreach (['nope', '', ['a_first'], 'A_FIRST'] as $group) {
            $result = $this->filtered()->respond($this->request(['group' => $group]), Thing::query(), $this->row(...));

            $this->assertSame('', $result['state']['group'], json_encode($group));
            $this->assertSame('50% off', $result['rows'][0]['name'], json_encode($group));
        }
    }

    private function filtered(): DataTable
    {
        return new DataTable(
            sortable: ['name'],
            searchable: ['name'],
            defaultSort: 'name',
            filters: [
                'starts' => static function (Builder $query, string $value): bool {
                    if (1 !== preg_match('/^[a-z]$/i', $value)) {
                        return false;
                    }

                    $query->where('name', 'like', $value . '%');

                    return true;
                },
            ],
            groups: [
                'a_first' => static fn (Builder $query) => $query->orderByRaw("CASE WHEN name LIKE 'A%' THEN 0 ELSE 1 END"),
            ],
        );
    }

    /**
     * @param  list<int>  $perPageOptions
     */
    private function table(array $perPageOptions = [25, 50, 100]): DataTable
    {
        return new DataTable(sortable: ['name'], searchable: ['name'], defaultSort: 'name', perPageOptions: $perPageOptions);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function request(array $query): Request
    {
        return Request::create('/', 'GET', $query);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Thing $thing): array
    {
        return ['id' => $thing->getKey(), 'name' => $thing->name];
    }
}

/**
 * @property string $name
 */
final class Thing extends Model
{
    public $timestamps = false;
    protected $table   = 'things';

    protected $guarded = [];
}
