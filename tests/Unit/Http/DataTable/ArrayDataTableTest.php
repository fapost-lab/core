<?php

declare(strict_types=1);

namespace Tests\Unit\Http\DataTable;

use App\Http\DataTable\ArrayDataTable;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The in-memory table keeps DataTable's query-string contract: whitelists, literal search, stable sort, page fallbacks.
 */
final class ArrayDataTableTest extends TestCase
{
    public function test_it_searches_literally_across_the_searchable_keys_ignoring_case(): void
    {
        $result = $this->respond(['search' => 'BE%']);
        $this->assertSame([], $result['rows']);

        $result = $this->respond(['search' => 'beta']);
        $this->assertSame(['b'], array_column($result['rows'], 'key'));

        // `note` is not searchable.
        $this->assertSame(0, $this->respond(['search' => 'hidden'])['meta']['total']);
    }

    public function test_it_sorts_by_a_whitelisted_key_and_keeps_ties_in_order(): void
    {
        $this->assertSame(['c', 'b', 'a'], array_column($this->respond(['sort' => '-key'])['rows'], 'key'));
        $this->assertSame(['a', 'c', 'b'], array_column($this->respond(['sort' => 'group'])['rows'], 'key'));
        $this->assertSame('key', $this->respond(['sort' => 'note'])['state']['sort']);
    }

    public function test_it_applies_only_declared_filter_values(): void
    {
        $result = $this->respond(['filter' => ['group' => 'one']]);
        $this->assertSame(['a', 'c'], array_column($result['rows'], 'key'));
        $this->assertSame(['group' => 'one'], $result['state']['filters']);

        $result = $this->respond(['filter' => ['group' => 'three', 'note' => 'x']]);
        $this->assertSame(3, $result['meta']['total']);
        $this->assertSame([], $result['state']['filters']);
    }

    public function test_it_pages_and_falls_back_to_the_last_page_and_the_default_size(): void
    {
        $result = $this->respond(['per_page' => '2', 'page' => '9'], [2, 4]);
        $this->assertSame(['total' => 3, 'perPage' => 2, 'currentPage' => 2, 'lastPage' => 2, 'from' => 3, 'to' => 3], $result['meta']);
        $this->assertSame(['c'], array_column($result['rows'], 'key'));

        $this->assertSame(2, $this->respond(['per_page' => '7'], [2, 4])['state']['perPage']);
        $this->assertSame(['sort' => 'key', 'perPage' => 2, 'perPageOptions' => [2, 4]], $this->respond([], [2, 4])['defaults']);
    }

    public function test_an_empty_list_has_no_range(): void
    {
        $table  = new ArrayDataTable(sortable: ['key'], searchable: ['key'], defaultSort: 'key');
        $result = $table->respond(Request::create('/', 'GET'), [], static fn (array $row): array => $row);

        $this->assertSame(['total' => 0, 'perPage' => 25, 'currentPage' => 1, 'lastPage' => 1, 'from' => null, 'to' => null], $result['meta']);
        $this->assertArrayNotHasKey('filters', $result['state']);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  list<int>             $perPage
     *
     * @return array<string, mixed>
     */
    private function respond(array $query, array $perPage = [25, 50]): array
    {
        $table = new ArrayDataTable(
            sortable: ['key', 'group'],
            searchable: ['key', 'label'],
            defaultSort: 'key',
            perPageOptions: $perPage,
            filters: ['group' => ['one', 'two']],
        );

        $rows = [
            ['key' => 'a', 'group' => 'one', 'label' => 'Alpha', 'note' => 'hidden'],
            ['key' => 'b', 'group' => 'two', 'label' => 'Beta', 'note' => 'hidden'],
            ['key' => 'c', 'group' => 'one', 'label' => 'Gamma', 'note' => 'hidden'],
        ];

        return $table->respond(Request::create('/', 'GET', $query), $rows, static fn (array $row): array => $row);
    }
}
