<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Tenancy\Infrastructure\CoreTenantDirectory;
use App\Domains\Tenancy\Models\TenantStatus as CoreTenantStatus;
use Fapost\Foundation\Tenancy\Contracts\TenantDirectoryInterface;
use Fapost\Foundation\Tenancy\DTO\TenantListQuery;
use Fapost\Foundation\Tenancy\DTO\TenantSummary;
use Fapost\Foundation\Tenancy\Enums\TenantSort;
use Fapost\Foundation\Tenancy\Enums\TenantStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * The directory contract against the real landlord table. The seeded `main` tenant is always
 * present, so list assertions narrow the result with `search`/`onlyIds` to the rows a test creates.
 */
final class CoreTenantDirectoryTest extends FeatureTestCase
{
    private const string MAIN_ID = '00000000-0000-0000-0000-000000000001';

    private TenantDirectoryInterface $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = $this->app->make(TenantDirectoryInterface::class);
    }

    public function test_the_container_resolves_the_contract_to_core_implementation(): void
    {
        $this->assertInstanceOf(CoreTenantDirectory::class, $this->directory);
    }

    public function test_it_paginates_and_reports_the_total(): void
    {
        foreach (['dir-a', 'dir-b', 'dir-c', 'dir-d', 'dir-e'] as $slug) {
            $this->tenant($slug);
        }

        $first  = $this->directory->list(new TenantListQuery(search: 'dir-', perPage: 2, page: 1));
        $last   = $this->directory->list(new TenantListQuery(search: 'dir-', perPage: 2, page: 3));
        $beyond = $this->directory->list(new TenantListQuery(search: 'dir-', perPage: 2, page: 4));

        $this->assertSame(5, $first->total);
        $this->assertSame(['dir-a', 'dir-b'], $this->slugs($first->items));
        $this->assertSame(1, $first->page);
        $this->assertSame(2, $first->perPage);
        $this->assertSame(['dir-e'], $this->slugs($last->items));
        $this->assertSame([], $beyond->items);
        $this->assertSame(5, $beyond->total);
    }

    public function test_search_is_a_case_insensitive_substring_match(): void
    {
        $this->tenant('acme-shop');
        $this->tenant('other');

        $result = $this->directory->list(new TenantListQuery(search: 'ME-S'));

        $this->assertSame(['acme-shop'], $this->slugs($result->items));
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        $this->tenant('a%b');
        $this->tenant('a_b');
        $this->tenant('axb');
        $this->tenant('a\\b');

        $this->assertSame(['a%b'], $this->slugs($this->directory->list(new TenantListQuery(search: 'a%b'))->items));
        $this->assertSame(['a_b'], $this->slugs($this->directory->list(new TenantListQuery(search: 'a_b'))->items));
        $this->assertSame(['a\\b'], $this->slugs($this->directory->list(new TenantListQuery(search: 'a\\b'))->items));
    }

    public function test_it_filters_by_status(): void
    {
        $this->tenant('st-active');
        $this->tenant('st-off', 'inactive');
        $this->tenant('st-susp', 'suspended');

        $result = $this->directory->list(new TenantListQuery(search: 'st-', status: TenantStatus::Suspended));

        $this->assertSame(['st-susp'], $this->slugs($result->items));
        $this->assertSame(TenantStatus::Suspended, $result->items[0]->status);
        $this->assertSame(1, $result->total);
    }

    public function test_a_pending_tenant_is_listed_and_found_as_pending(): void
    {
        $id = $this->tenant('pend-a', 'pending');
        $this->tenant('pend-b');

        $found  = $this->directory->find($id);
        $listed = $this->directory->list(new TenantListQuery(search: 'pend-', status: TenantStatus::Pending));

        $this->assertSame(TenantStatus::Pending, $found?->status);
        $this->assertSame(['pend-a'], $this->slugs($listed->items));
    }

    public function test_only_ids_and_except_ids_narrow_the_list(): void
    {
        $a = $this->tenant('ids-a');
        $b = $this->tenant('ids-b');
        $c = $this->tenant('ids-c');

        $only   = $this->directory->list(new TenantListQuery(onlyIds: [$a, $c]));
        $except = $this->directory->list(new TenantListQuery(search: 'ids-', exceptIds: [$a]));
        $both   = $this->directory->list(new TenantListQuery(onlyIds: [$a, $b], exceptIds: [$a]));
        $empty  = $this->directory->list(new TenantListQuery(exceptIds: []));

        $this->assertSame(['ids-a', 'ids-c'], $this->slugs($only->items));
        $this->assertSame(['ids-b', 'ids-c'], $this->slugs($except->items));
        $this->assertSame(['ids-b'], $this->slugs($both->items));
        $this->assertSame(4, $empty->total, 'An empty exceptIds excludes nothing (seeded main plus three).');
    }

    public function test_an_empty_only_ids_matches_nothing(): void
    {
        $this->tenant('none-a');

        $result = $this->directory->list(new TenantListQuery(onlyIds: []));

        $this->assertSame([], $result->items);
        $this->assertSame(0, $result->total);
    }

    public function test_it_sorts_by_slug_and_by_creation_time_in_both_directions(): void
    {
        $this->tenant('so-b', createdAt: '2026-01-02 00:00:00');
        $this->tenant('so-c', createdAt: '2026-01-01 00:00:00');
        $this->tenant('so-a', createdAt: '2026-01-03 00:00:00');

        $slug     = new TenantListQuery(search: 'so-', sort: TenantSort::Slug);
        $slugDesc = new TenantListQuery(search: 'so-', sort: TenantSort::Slug, descending: true);
        $date     = new TenantListQuery(search: 'so-', sort: TenantSort::CreatedAt);
        $dateDesc = new TenantListQuery(search: 'so-', sort: TenantSort::CreatedAt, descending: true);

        $this->assertSame(['so-a', 'so-b', 'so-c'], $this->slugs($this->directory->list($slug)->items));
        $this->assertSame(['so-c', 'so-b', 'so-a'], $this->slugs($this->directory->list($slugDesc)->items));
        $this->assertSame(['so-c', 'so-b', 'so-a'], $this->slugs($this->directory->list($date)->items));
        $this->assertSame(['so-a', 'so-b', 'so-c'], $this->slugs($this->directory->list($dateDesc)->items));
    }

    public function test_find_returns_a_summary_or_null(): void
    {
        $id = $this->tenant('find-me', 'inactive', '2026-03-04 05:06:07');

        $summary = $this->directory->find($id);

        $this->assertNotNull($summary);
        $this->assertSame($id, $summary->id);
        $this->assertSame('find-me', $summary->slug);
        $this->assertSame(TenantStatus::Inactive, $summary->status);
        $this->assertSame('2026-03-04T05:06:07+00:00', $summary->createdAt?->format('c'));
        $this->assertNull($this->directory->find($this->unknownId()));
    }

    public function test_find_accepts_an_uppercase_id_and_returns_null_for_a_malformed_one(): void
    {
        $id = $this->tenant('case-t');

        $this->assertSame($id, $this->directory->find(mb_strtoupper($id))?->id);
        $this->assertNull($this->directory->find('not-an-id'));
        $this->assertNull($this->directory->find(''));
        $this->assertNull($this->directory->find((string) Str::ulid()));
    }

    public function test_find_many_drops_malformed_ids_and_keys_by_the_normalized_id(): void
    {
        $id = $this->tenant('norm-t');

        $found = $this->directory->findMany([mb_strtoupper($id), 'garbage', '']);

        $this->assertSame([$id], array_keys($found));
        $this->assertSame([], $this->directory->findMany(['garbage']));
    }

    public function test_malformed_only_ids_are_dropped_and_none_left_means_no_result(): void
    {
        $id = $this->tenant('mal-a');

        $mixed = $this->directory->list(new TenantListQuery(onlyIds: ['garbage', mb_strtoupper($id)]));
        $none  = $this->directory->list(new TenantListQuery(onlyIds: ['garbage']));

        $this->assertSame(['mal-a'], $this->slugs($mixed->items));
        $this->assertSame([], $none->items);
        $this->assertSame(0, $none->total);
    }

    public function test_malformed_except_ids_are_dropped(): void
    {
        $id = $this->tenant('mex-a');
        $this->tenant('mex-b');

        $result = $this->directory->list(new TenantListQuery(search: 'mex-', exceptIds: ['garbage', $id]));

        $this->assertSame(['mex-b'], $this->slugs($result->items));
    }

    public function test_core_and_foundation_tenant_statuses_stay_in_step(): void
    {
        $values = static fn (array $cases): array => array_map(static fn ($case) => $case->value, $cases);

        $this->assertEqualsCanonicalizing(
            $values(CoreTenantStatus::cases()),
            $values(TenantStatus::cases()),
            'A status added on one side must be added and mapped on the other.',
        );
    }

    public function test_creation_time_sort_puts_rows_without_one_last_in_both_directions(): void
    {
        $this->tenant('nul-a', createdAt: '2026-01-02 00:00:00');
        $this->tenant('nul-b');
        $this->tenant('nul-c', createdAt: '2026-01-01 00:00:00');

        $asc  = $this->directory->list(new TenantListQuery(search: 'nul-', sort: TenantSort::CreatedAt));
        $desc = $this->directory->list(new TenantListQuery(search: 'nul-', sort: TenantSort::CreatedAt, descending: true));

        $this->assertSame(['nul-c', 'nul-a', 'nul-b'], $this->slugs($asc->items));
        $this->assertSame(['nul-a', 'nul-c', 'nul-b'], $this->slugs($desc->items));
    }

    public function test_urls_always_end_with_exactly_one_slash(): void
    {
        $id = $this->tenant('slash-t');

        foreach (['single', 'host'] as $mode) {
            config(['tenancy.resolution' => $mode, 'tenancy.base_domain' => 'fapost.test']);

            $this->assertMatchesRegularExpression('#[^/]/$#', $this->directory->find($id)?->url ?? '', $mode);
        }
    }

    public function test_a_tenant_without_a_creation_time_has_a_null_created_at(): void
    {
        $summary = $this->directory->find(self::MAIN_ID);

        $this->assertNotNull($summary);
        $this->assertNull($summary->createdAt);
    }

    public function test_find_many_is_keyed_by_id_and_skips_unknown_ids(): void
    {
        $a = $this->tenant('many-a');
        $b = $this->tenant('many-b');

        $found = $this->directory->findMany([$a, $this->unknownId(), $b]);

        $this->assertEqualsCanonicalizing([$a, $b], array_keys($found));
        $this->assertSame('many-a', $found[$a]->slug);
        $this->assertSame([], $this->directory->findMany([]));
        $this->assertSame([], $this->directory->findMany([$this->unknownId()]));
    }

    public function test_urls_in_single_mode_use_the_application_url(): void
    {
        config(['tenancy.resolution' => 'single']);
        $id = $this->tenant('single-t');

        $summary = $this->directory->find($id);

        $this->assertNotNull($summary);
        $this->assertSame(mb_rtrim(url('/'), '/') . '/', $summary->url);
        $this->assertSame(url('/admin/login'), $summary->loginUrl);
    }

    public function test_urls_in_host_mode_point_at_the_tenant_host(): void
    {
        config([
            'tenancy.resolution'  => 'host',
            'tenancy.base_domain' => 'fapost.test',
            'app.url'             => 'https://fapost.test:8443',
        ]);
        $id = $this->tenant('host-t');

        $summary = $this->directory->find($id);

        $this->assertNotNull($summary);
        $this->assertSame('https://host-t.fapost.test:8443/', $summary->url);
        $this->assertSame('https://host-t.fapost.test:8443/admin/login', $summary->loginUrl);
    }

    private function unknownId(): string
    {
        return mb_strtolower((string) Str::ulid()->toRfc4122());
    }

    private function tenant(string $slug, string $status = 'active', ?string $createdAt = null): string
    {
        $id = mb_strtolower((string) Str::ulid()->toRfc4122());

        DB::connection('landlord')->table('tenants')->insert([
            'id'          => $id,
            'slug'        => $slug,
            'schema_name' => 'schema_' . md5($slug),
            'status'      => $status,
            'config'      => '{}',
            'created_at'  => $createdAt,
        ]);

        return $id;
    }

    /**
     * @param  list<TenantSummary>  $items
     * @return list<string>
     */
    private function slugs(array $items): array
    {
        return array_map(static fn (TenantSummary $item): string => $item->slug, $items);
    }
}
