<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Contact\Models\ContactTag;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Contact segments on the Inertia console: the list, the rule builder's server side (what is stored, what is refused),
 * the size recount, and who may do which.
 */
final class ContactSegmentsConsoleTest extends InertiaConsoleTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string OTHER_TENANT_ID = '00000000-0000-0000-0000-0000000000ff';

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->assistant = Assistant::factory()->create();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function validConditions(): array
    {
        return [
            'tag has'                           => [['type' => 'tag', 'operator' => 'has', 'value' => [' vip ']], ['type' => 'tag', 'operator' => 'has', 'value' => ['vip']]],
            'tag not has'                       => [['type' => 'tag', 'operator' => 'not_has', 'value' => ['spam']], ['type' => 'tag', 'operator' => 'not_has', 'value' => ['spam']]],
            'language in'                       => [['type' => 'language', 'operator' => 'in', 'value' => ['en', 'pt-BR', 'en']], ['type' => 'language', 'operator' => 'in', 'value' => ['en', 'pt-BR']]],
            'language eq'                       => [['type' => 'language', 'operator' => 'eq', 'value' => ['uk']], ['type' => 'language', 'operator' => 'eq', 'value' => ['uk']]],
            'platform in'                       => [['type' => 'platform', 'operator' => 'in', 'value' => ['telegram', 'email']], ['type' => 'platform', 'operator' => 'in', 'value' => ['telegram', 'email']]],
            'platform eq'                       => [['type' => 'platform', 'operator' => 'eq', 'value' => ['whatsapp']], ['type' => 'platform', 'operator' => 'eq', 'value' => ['whatsapp']]],
            'attribute eq'                      => [['type' => 'attribute', 'key' => ' profile.city ', 'operator' => 'eq', 'value' => ['Kyiv']], ['type' => 'attribute', 'key' => 'profile.city', 'operator' => 'eq', 'value' => ['Kyiv']]],
            'attribute ne'                      => [['type' => 'attribute', 'key' => 'age', 'operator' => 'ne', 'value' => ['18']], ['type' => 'attribute', 'key' => 'age', 'operator' => 'ne', 'value' => ['18']]],
            'attribute exists'                  => [['type' => 'attribute', 'key' => 'age', 'operator' => 'exists', 'value' => ['junk']], ['type' => 'attribute', 'key' => 'age', 'operator' => 'exists']],
            'group in'                          => [['type' => 'group', 'operator' => 'in', 'value' => ['@group']], ['type' => 'group', 'operator' => 'in', 'value' => ['@group']]],
            'group not in'                      => [['type' => 'group', 'operator' => 'not_in', 'value' => ['@group']], ['type' => 'group', 'operator' => 'not_in', 'value' => ['@group']]],
            'key of a non-attribute is dropped' => [['type' => 'tag', 'key' => 'x', 'operator' => 'has', 'value' => ['vip']], ['type' => 'tag', 'operator' => 'has', 'value' => ['vip']]],
        ];
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidSegments(): array
    {
        $one = static fn (array $condition): array => ['conditions' => [$condition]];

        return [
            'no name'                      => [['name' => ''], 'name'],
            'name too long'                => [['name' => str_repeat('a', 256)], 'name'],
            'match outside the enum'       => [['match' => 'some'], 'match'],
            'no conditions field'          => [['conditions' => null], 'conditions'],
            'conditions that are no array' => [['conditions' => 'tag'], 'conditions'],
            'too many conditions'          => [['conditions' => array_fill(0, 51, ['type' => 'tag', 'operator' => 'has', 'value' => ['a']])], 'conditions'],
            'unknown type'                 => [$one(['type' => 'age', 'operator' => 'has', 'value' => ['a']]), 'conditions.0.type'],
            'operator of another type'     => [$one(['type' => 'group', 'operator' => 'has', 'value' => ['a']]), 'conditions.0.operator'],
            'unknown operator'             => [$one(['type' => 'tag', 'operator' => 'like', 'value' => ['a']]), 'conditions.0.operator'],
            'attribute without a key'      => [$one(['type' => 'attribute', 'operator' => 'eq', 'value' => ['a']]), 'conditions.0.key'],
            'attribute key with two dots'  => [$one(['type' => 'attribute', 'key' => 'a..b', 'operator' => 'eq', 'value' => ['a']]), 'conditions.0.key'],
            'attribute key with a dash'    => [$one(['type' => 'attribute', 'key' => 'a-b', 'operator' => 'eq', 'value' => ['a']]), 'conditions.0.key'],
            'two tags in one condition'    => [$one(['type' => 'tag', 'operator' => 'has', 'value' => ['a', 'b']]), 'conditions.0.value'],
            'two values for eq'            => [$one(['type' => 'language', 'operator' => 'eq', 'value' => ['en', 'uk']]), 'conditions.0.value'],
            'two values for attribute eq'  => [$one(['type' => 'attribute', 'key' => 'k', 'operator' => 'eq', 'value' => ['a', 'b']]), 'conditions.0.value'],
            'no tag'                       => [$one(['type' => 'tag', 'operator' => 'has', 'value' => []]), 'conditions.0.value'],
            'blank tag'                    => [$one(['type' => 'tag', 'operator' => 'has', 'value' => ['  ']]), 'conditions.0.value'],
            'empty in'                     => [$one(['type' => 'language', 'operator' => 'in', 'value' => []]), 'conditions.0.value'],
            'language off the pattern'     => [$one(['type' => 'language', 'operator' => 'in', 'value' => ['english language']]), 'conditions.0.value'],
            'unknown platform'             => [$one(['type' => 'platform', 'operator' => 'in', 'value' => ['sms']]), 'conditions.0.value'],
            'group that does not exist'    => [$one(['type' => 'group', 'operator' => 'in', 'value' => ['00000000-0000-0000-0000-00000000dead']]), 'conditions.0.value'],
            'group id that is no uuid'     => [$one(['type' => 'group', 'operator' => 'not_in', 'value' => ['nope']]), 'conditions.0.value'],
            'too many values'              => [$one(['type' => 'language', 'operator' => 'in', 'value' => array_map(static fn (int $n): string => "l{$n}", range(1, 101))]), 'conditions.0.value'],
            'a value that is too long'     => [$one(['type' => 'tag', 'operator' => 'has', 'value' => [str_repeat('a', 256)]]), 'conditions.0.value.0'],
        ];
    }

    public function test_the_list_shows_the_segments_of_the_tenant(): void
    {
        $counted = Carbon::parse('2026-10-01 12:00:00');
        $alpha   = $this->segment('Alpha', [
            ['type' => 'tag', 'operator' => 'has', 'value' => ['vip']],
            ['type' => 'language', 'operator' => 'in', 'value' => ['en']],
        ], match: 'any', size: 7, countedAt: $counted);
        $beta = $this->segment('Beta', []);
        $this->segment('Foreign', [], tenantId: self::OTHER_TENANT_ID);

        $this->actingAs($this->admin())
            ->get($this->listUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/ContactSegments/Index')
                ->where('table.meta.total', 2)
                ->where('table.state', ['search' => '', 'sort' => 'name', 'perPage' => 25])
                ->where('table.rows.0.id', (string) $alpha->getKey())
                ->where('table.rows.0.name', 'Alpha')
                ->where('table.rows.0.match', 'any')
                ->where('table.rows.0.conditionsCount', 2)
                ->where('table.rows.0.size', 7)
                ->where('table.rows.0.countedAt', $counted->toIso8601String())
                ->where('table.rows.0.editUrl', "/assistant/{$this->assistant->getKey()}/contact-segments/{$alpha->getKey()}/edit")
                ->where('table.rows.0.deleteUrl', "/assistant/{$this->assistant->getKey()}/contact-segments/{$alpha->getKey()}")
                ->where('table.rows.0.countUrl', "/assistant/{$this->assistant->getKey()}/contact-segments/{$alpha->getKey()}/count")
                ->where('table.rows.1.id', (string) $beta->getKey())
                ->where('table.rows.1.match', 'all')
                ->where('table.rows.1.conditionsCount', 0)
                ->where('table.rows.1.size', null)
                ->where('table.rows.1.countedAt', null)
                ->where('can', ['create' => true, 'update' => true, 'delete' => true])
                ->where('urls.create', "/assistant/{$this->assistant->getKey()}/contact-segments/create")
                ->where('table.defaults', ['sort' => 'name', 'perPage' => 25, 'perPageOptions' => [25, 50, 100]])
                ->etc());
    }

    public function test_the_list_searches_by_name_ignoring_case_and_wildcards(): void
    {
        $this->segment('Premium customers', []);
        $this->segment('Newsletter', []);
        $this->segment('100% fans', []);

        $this->actingAs($this->admin());

        $this->get($this->listUrl('?search=PREMIUM'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.name', 'Premium customers')
                ->etc());

        $this->get($this->listUrl('?search=' . urlencode('%')))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.name', '100% fans')
                ->etc());
    }

    public function test_the_list_sorts_by_name_size_and_count_date_and_ignores_other_columns(): void
    {
        $this->segment('Alpha', [], size: 5, countedAt: Carbon::parse('2026-10-01'));
        $this->segment('Beta', [], size: 50, countedAt: Carbon::parse('2026-09-01'));

        $this->actingAs($this->admin());

        $first = static fn (string $name) => static fn (AssertableInertia $page) => $page->where('table.rows.0.name', $name)->etc();

        $this->get($this->listUrl('?sort=-name'))->assertInertia($first('Beta'));
        $this->get($this->listUrl('?sort=-cached_count'))->assertInertia($first('Beta'));
        $this->get($this->listUrl('?sort=cached_count'))->assertInertia($first('Alpha'));
        $this->get($this->listUrl('?sort=-cached_count_at'))->assertInertia($first('Alpha'));
        $this->get($this->listUrl('?sort=cached_count_at'))->assertInertia($first('Beta'));

        $this->get($this->listUrl('?sort=tenant_id;drop'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.state.sort', 'name')->etc());
    }

    public function test_the_list_is_paged(): void
    {
        foreach (range(1, 30) as $number) {
            $this->segment(sprintf('Segment %02d', $number), []);
        }

        $this->actingAs($this->admin())
            ->get($this->listUrl('?page=2&per_page=25'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta', ['total' => 30, 'perPage' => 25, 'currentPage' => 2, 'lastPage' => 2, 'from' => 26, 'to' => 30])
                ->has('table.rows', 5)
                ->etc());
    }

    public function test_the_create_screen_gets_the_schema_from_the_domain_and_the_options(): void
    {
        $group = $this->group('Beta group');
        $this->group('Alpha group');
        $this->group('Foreign group', self::OTHER_TENANT_ID);
        Contact::factory()->forTenant(self::TENANT_ID)->create(['language' => 'uk']);
        Contact::factory()->forTenant(self::TENANT_ID)->create(['language' => 'en']);
        Contact::factory()->forTenant(self::TENANT_ID)->create(['language' => 'en']);
        Contact::factory()->forTenant(self::OTHER_TENANT_ID)->create(['language' => 'de']);
        ContactTag::factory()->create(['contact_id' => Contact::factory()->forTenant(self::TENANT_ID)->create()->getKey(), 'tag' => 'vip']);

        $this->actingAs($this->admin())
            ->get($this->listUrl('/create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/ContactSegments/Create')
                ->where('urls.submit', "/assistant/{$this->assistant->getKey()}/contact-segments")
                ->where('schema.match', [
                    ['value' => 'all', 'label' => 'All conditions (AND)'],
                    ['value' => 'any', 'label' => 'Any condition (OR)'],
                ])
                ->where('schema.types.0', [
                    'value'     => 'tag',
                    'label'     => 'Tag',
                    'needsKey'  => false,
                    'operators' => [
                        ['value' => 'has', 'label' => 'has tag', 'arity' => 'one'],
                        ['value' => 'not_has', 'label' => 'does not have tag', 'arity' => 'one'],
                    ],
                ])
                ->where('schema.types.3.value', 'attribute')
                ->where('schema.types.3.needsKey', true)
                ->where('schema.types.3.operators.2', ['value' => 'exists', 'label' => 'is set', 'arity' => 'none'])
                ->where('schema.types.4.operators.1', ['value' => 'not_in', 'label' => 'is none of', 'arity' => 'many'])
                ->where('options.platforms.0', ['value' => 'telegram', 'label' => 'Telegram'])
                ->where('options.groups', [
                    ['id' => $this->groupIdByName('Alpha group'), 'name' => 'Alpha group'],
                    ['id' => (string) $group->getKey(), 'name' => 'Beta group'],
                ])
                ->where('options.languages', ['en', 'uk'])
                ->where('options.tags', ['vip'])
                ->etc());
    }

    #[DataProvider('validConditions')]
    public function test_a_segment_is_stored_in_the_shape_filament_writes(array $sent, array $stored): void
    {
        $group  = $this->group('VIP');
        $sent   = $this->fillGroup($sent, $group);
        $stored = $this->fillGroup($stored, $group);

        $this->actingAs($this->admin())
            ->post($this->listUrl(), ['name' => '  Buyers ', 'match' => 'any', 'conditions' => [$sent]])
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect($this->listUrl())
            ->assertInertiaFlash('success');

        $segment = ContactSegment::query()->sole();

        $this->assertSame(self::TENANT_ID, $segment->tenant_id);
        $this->assertSame('Buyers', $segment->name);
        $this->assertNull($segment->cached_count);
        // jsonb does not keep the order of keys.
        $this->assertEquals(['match' => 'any', 'conditions' => [$stored]], $segment->rules);
    }

    public function test_a_segment_without_conditions_is_allowed(): void
    {
        $this->actingAs($this->admin())
            ->post($this->listUrl(), ['name' => 'Everyone', 'match' => 'all', 'conditions' => []])
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect($this->listUrl());

        $this->assertEquals(['match' => 'all', 'conditions' => []], ContactSegment::query()->sole()->rules);
    }

    public function test_a_segment_may_share_its_name_with_another(): void
    {
        $this->segment('Twin', []);

        $this->actingAs($this->admin())
            ->post($this->listUrl(), ['name' => 'Twin', 'match' => 'all', 'conditions' => []])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(2, ContactSegment::query()->count());
    }

    #[DataProvider('invalidSegments')]
    public function test_invalid_rules_are_refused_with_the_error_on_the_field(array $payload, string $errorKey): void
    {
        $this->group('Real');

        $this->actingAs($this->admin())
            ->post($this->listUrl(), $payload + ['name' => 'Bad', 'match' => 'all', 'conditions' => []])
            ->assertSessionHasErrors($errorKey);

        $this->assertSame(0, ContactSegment::query()->count());
    }

    public function test_a_group_of_another_tenant_cannot_be_used(): void
    {
        $foreign = $this->group('Foreign', self::OTHER_TENANT_ID);

        $this->actingAs($this->admin())
            ->post($this->listUrl(), ['name' => 'X', 'match' => 'all', 'conditions' => [
                ['type' => 'group', 'operator' => 'in', 'value' => [(string) $foreign->getKey()]],
            ]])
            ->assertSessionHasErrors('conditions.0.value');
    }

    public function test_errors_are_keyed_by_the_row_that_is_wrong(): void
    {
        $this->actingAs($this->admin())
            ->post($this->listUrl(), ['name' => 'X', 'match' => 'all', 'conditions' => [
                ['type' => 'tag', 'operator' => 'has', 'value' => ['ok']],
                ['type' => 'tag', 'operator' => 'in', 'value' => ['ok']],
                ['type' => 'tag', 'operator' => 'has', 'value' => ['a', 'b']],
            ]])
            ->assertSessionHasErrors(['conditions.1.operator', 'conditions.2.value'])
            ->assertSessionDoesntHaveErrors(['conditions.0.type', 'conditions.0.operator', 'conditions.0.value']);
    }

    public function test_conditions_are_checked_even_when_another_field_fails(): void
    {
        $this->actingAs($this->admin())
            ->post($this->listUrl(), ['name' => '', 'match' => 'all', 'conditions' => [
                ['type' => 'tag', 'operator' => 'has', 'value' => ['a', 'b']],
                ['type' => 'weather', 'operator' => 'is', 'value' => ['x']],
                ['type' => 'group', 'operator' => 'in', 'value' => ['00000000-0000-0000-0000-00000000dead']],
            ]])
            ->assertSessionHasErrors(['name', 'conditions.0.value', 'conditions.1.type', 'conditions.2.value']);
    }

    public function test_conditions_sent_with_sparse_keys_are_refused_not_crashed_on(): void
    {
        $this->actingAs($this->admin())
            ->post($this->listUrl(), ['name' => 'X', 'match' => 'all', 'conditions' => [
                5 => ['type' => 'bogus', 'operator' => 'has'],
                9 => ['type' => 'tag', 'operator' => 'in', 'value' => ['a']],
            ]])
            ->assertSessionHasErrors(['conditions.5.type', 'conditions.9.operator']);

        $this->assertSame(0, ContactSegment::query()->count());
    }

    public function test_a_condition_the_console_cannot_show_is_kept_for_the_form_not_dropped(): void
    {
        $segment = $this->segment('Odd', [['type' => 'tag', 'operator' => 'has', 'value' => ['a']], 'broken']);

        $this->actingAs($this->admin())
            ->get($this->listUrl("/{$segment->getKey()}/edit"))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('segment.conditions', 2)
                ->where('segment.conditions.1', ['type' => '', 'key' => '', 'operator' => '', 'value' => []])
                ->etc());
    }

    public function test_changing_replaces_the_rules_and_keeps_the_stored_size(): void
    {
        $counted = Carbon::parse('2026-10-01 12:00:00');
        $segment = $this->segment('Old', [['type' => 'tag', 'operator' => 'has', 'value' => ['vip']]], size: 9, countedAt: $counted);

        $this->actingAs($this->admin())
            ->put($this->listUrl("/{$segment->getKey()}"), [
                'name'       => 'New',
                'match'      => 'any',
                'conditions' => [['type' => 'platform', 'operator' => 'eq', 'value' => ['telegram']]],
            ])
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect($this->listUrl())
            ->assertInertiaFlash('success');

        $segment->refresh();

        $this->assertSame('New', $segment->name);
        $this->assertEquals(['match' => 'any', 'conditions' => [['type' => 'platform', 'operator' => 'eq', 'value' => ['telegram']]]], $segment->rules);
        $this->assertSame(9, $segment->cached_count);
        $this->assertTrue($counted->equalTo($segment->cached_count_at));
    }

    public function test_the_edit_screen_lays_out_what_is_stored(): void
    {
        $group   = $this->group('G');
        $counted = Carbon::parse('2026-10-01 12:00:00');
        $segment = $this->segment('Mixed', [
            // A scalar, as an older record may hold it.
            ['type' => 'tag', 'operator' => 'has', 'value' => 'vip'],
            ['type' => 'attribute', 'key' => 'profile.city', 'operator' => 'eq', 'value' => ['Kyiv']],
            ['type' => 'attribute', 'key' => 'age', 'operator' => 'exists'],
            // A group that was deleted, and a type this version does not know.
            ['type' => 'group', 'operator' => 'not_in', 'value' => [(string) $group->getKey(), '00000000-0000-0000-0000-00000000dead']],
            ['type' => 'weather', 'operator' => 'is', 'value' => ['rain']],
        ], match: 'any', size: 3, countedAt: $counted);

        $this->actingAs($this->admin())
            ->get($this->listUrl("/{$segment->getKey()}/edit"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/ContactSegments/Edit')
                ->where('segment.id', (string) $segment->getKey())
                ->where('segment.name', 'Mixed')
                ->where('segment.match', 'any')
                ->where('segment.conditions.0', ['type' => 'tag', 'key' => '', 'operator' => 'has', 'value' => ['vip']])
                ->where('segment.conditions.1', ['type' => 'attribute', 'key' => 'profile.city', 'operator' => 'eq', 'value' => ['Kyiv']])
                ->where('segment.conditions.2', ['type' => 'attribute', 'key' => 'age', 'operator' => 'exists', 'value' => []])
                ->where('segment.conditions.3.value', [(string) $group->getKey(), '00000000-0000-0000-0000-00000000dead'])
                ->where('segment.conditions.4', ['type' => 'weather', 'key' => '', 'operator' => 'is', 'value' => ['rain']])
                ->where('size', 3)
                ->where('countedAt', $counted->toIso8601String())
                ->where('urls.submit', "/assistant/{$this->assistant->getKey()}/contact-segments/{$segment->getKey()}")
                ->has('schema.types', 5)
                ->has('options.groups', 1)
                ->etc());
    }

    public function test_a_segment_that_cannot_be_shown_has_to_be_fixed_before_it_is_saved(): void
    {
        $segment = $this->segment('Legacy', [['type' => 'tag', 'operator' => 'has', 'value' => ['a', 'b']]]);

        $this->actingAs($this->admin())
            ->put($this->listUrl("/{$segment->getKey()}"), [
                'name'       => 'Legacy',
                'match'      => 'all',
                'conditions' => [['type' => 'tag', 'operator' => 'has', 'value' => ['a', 'b']]],
            ])
            ->assertSessionHasErrors('conditions.0.value');

        $this->assertEquals([['type' => 'tag', 'operator' => 'has', 'value' => ['a', 'b']]], $segment->fresh()->rules['conditions']);
    }

    public function test_a_recount_stores_the_size_over_all_contacts_of_the_tenant(): void
    {
        $segment = $this->segment('VIPs', [['type' => 'tag', 'operator' => 'has', 'value' => ['vip']]]);

        // A contact that never wrote to this assistant still counts: the size is the tenant's, not the assistant's.
        foreach ([self::TENANT_ID, self::TENANT_ID, self::OTHER_TENANT_ID] as $tenantId) {
            ContactTag::factory()->create([
                'contact_id' => Contact::factory()->forTenant($tenantId)->create()->getKey(),
                'tag'        => 'vip',
            ]);
        }

        $this->actingAs($this->admin())
            ->post($this->listUrl("/{$segment->getKey()}/count"))
            ->assertRedirect($this->listUrl())
            ->assertInertiaFlash('success', '2 contacts match this segment.');

        $segment->refresh();

        $this->assertSame(2, $segment->cached_count);
        $this->assertNotNull($segment->cached_count_at);
    }

    public function test_a_recount_goes_back_to_the_list_the_user_was_on(): void
    {
        $segment = $this->segment('One', []);
        $url     = $this->listUrl('?search=o&sort=-name');

        $this->actingAs($this->admin())
            ->from($url)
            ->post($this->listUrl("/{$segment->getKey()}/count"))
            ->assertRedirect($url);
    }

    public function test_a_segment_is_deleted_and_the_draft_that_targeted_it_is_left_alone(): void
    {
        $segment = $this->segment('Doomed', []);
        $kept    = $this->segment('Kept', []);
        $draft   = Broadcast::query()->create([
            'tenant_id'         => self::TENANT_ID,
            'assistant_id'      => (string) $this->assistant->getKey(),
            'name'              => 'Promo',
            'message'           => 'Hello!',
            'target_type'       => 'segment',
            'target_segment_id' => $segment->getKey(),
            'status'            => 'draft',
        ]);

        $url = $this->listUrl('?search=o&page=1');

        $this->actingAs($this->admin())
            ->from($url)
            ->delete($this->listUrl("/{$segment->getKey()}"))
            ->assertRedirect($url)
            ->assertInertiaFlash('success');

        $this->assertModelMissing($segment);
        $this->assertModelExists($kept);
        // Nothing stops the delete, and the draft keeps a dangling id that resolves to nobody.
        $this->assertSame((string) $segment->getKey(), $draft->fresh()->target_segment_id);
    }

    public function test_a_segment_of_another_tenant_or_a_malformed_id_is_not_found(): void
    {
        $foreign = $this->segment('Foreign', [], tenantId: self::OTHER_TENANT_ID);
        $valid   = ['name' => 'Hijacked', 'match' => 'all', 'conditions' => []];

        $this->actingAs($this->admin());

        foreach ([(string) $foreign->getKey(), 'not-an-id'] as $id) {
            $this->get($this->listUrl("/{$id}/edit"))->assertNotFound();
            $this->put($this->listUrl("/{$id}"), $valid)->assertNotFound();
            $this->delete($this->listUrl("/{$id}"))->assertNotFound();
            $this->post($this->listUrl("/{$id}/count"))->assertNotFound();
        }

        $this->assertDatabaseHas('contact_segments', ['id' => $foreign->getKey(), 'name' => 'Foreign']);
        $this->assertNull($foreign->fresh()->cached_count);
    }

    public function test_a_viewer_sees_the_list_without_the_write_controls_and_cannot_write(): void
    {
        $segment = $this->segment('Readable', []);
        $valid   = ['name' => 'Changed', 'match' => 'all', 'conditions' => []];

        $this->actingAs($this->userWith(Permission::ViewContacts))
            ->get($this->listUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('can', ['create' => false, 'update' => false, 'delete' => false])
                ->etc());

        $this->get($this->listUrl('/create'))->assertForbidden();
        $this->post($this->listUrl(), $valid)->assertForbidden();
        $this->get($this->listUrl("/{$segment->getKey()}/edit"))->assertForbidden();
        $this->put($this->listUrl("/{$segment->getKey()}"), $valid)->assertForbidden();
        $this->delete($this->listUrl("/{$segment->getKey()}"))->assertForbidden();
        $this->post($this->listUrl("/{$segment->getKey()}/count"))->assertForbidden();

        $this->assertDatabaseHas('contact_segments', ['id' => $segment->getKey(), 'name' => 'Readable']);
        $this->assertNull($segment->fresh()->cached_count);
        $this->assertSame(1, ContactSegment::query()->count());
    }

    public function test_a_contact_manager_and_a_broadcast_manager_may_write(): void
    {
        foreach ([Permission::ManageContacts, Permission::ManageBroadcast] as $permission) {
            $this->actingAs($this->userWith($permission))
                ->get($this->listUrl())
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('can', ['create' => true, 'update' => true, 'delete' => true])
                    ->etc());

            $this->post($this->listUrl(), ['name' => $permission->value, 'match' => 'all', 'conditions' => []])
                ->assertSessionDoesntHaveErrors()
                ->assertRedirect($this->listUrl());
        }

        $this->assertSame(2, ContactSegment::query()->count());
    }

    public function test_the_content_manager_role_can_do_everything_the_screen_offers(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::ContentManager->value);
        $user->assistants()->attach($this->assistant);
        $segment = $this->segment('Mine', []);

        $this->actingAs($user)->get($this->listUrl())->assertOk();
        $this->get($this->listUrl('/create'))->assertOk();
        $this->get($this->listUrl("/{$segment->getKey()}/edit"))->assertOk();
        $this->post($this->listUrl("/{$segment->getKey()}/count"))->assertRedirect();
        $this->post($this->listUrl(), ['name' => 'New', 'match' => 'all', 'conditions' => []])->assertRedirect($this->listUrl());
        $this->delete($this->listUrl("/{$segment->getKey()}"))->assertRedirect();

        $this->assertSame(1, ContactSegment::query()->count());
    }

    public function test_a_user_without_contact_permissions_is_refused_everywhere(): void
    {
        $segment = $this->segment('Hidden', []);

        $this->actingAs($this->userWith(Permission::ManageFlowGroups));

        $this->get($this->listUrl())->assertForbidden();
        $this->get($this->listUrl('/create'))->assertForbidden();
        $this->get($this->listUrl("/{$segment->getKey()}/edit"))->assertForbidden();
        $this->post($this->listUrl("/{$segment->getKey()}/count"))->assertForbidden();
        $this->delete($this->listUrl("/{$segment->getKey()}"))->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->get($this->listUrl())->assertRedirect(route('filament.admin.auth.login'));
    }

    private function listUrl(string $suffix = ''): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/contact-segments{$suffix}");
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
     */
    private function segment(string $name, array $conditions, string $match = 'all', ?int $size = null, ?Carbon $countedAt = null, string $tenantId = self::TENANT_ID): ContactSegment
    {
        return ContactSegment::query()->create([
            'tenant_id'       => $tenantId,
            'name'            => $name,
            'rules'           => ['match' => $match, 'conditions' => $conditions],
            'cached_count'    => $size,
            'cached_count_at' => $countedAt,
        ]);
    }

    private function group(string $name, string $tenantId = self::TENANT_ID): ContactGroup
    {
        return ContactGroup::query()->create(['tenant_id' => $tenantId, 'name' => $name]);
    }

    private function groupIdByName(string $name): string
    {
        return (string) ContactGroup::query()->where('name', $name)->value('id');
    }

    /**
     * A data provider cannot know the id of a group made in the test, so it writes `@group` and the test fills it in.
     *
     * @param  array<string, mixed>  $condition
     *
     * @return array<string, mixed>
     */
    private function fillGroup(array $condition, ContactGroup $group): array
    {
        if (isset($condition['value'])) {
            $condition['value'] = array_map(
                static fn (string $value): string => '@group' === $value ? (string) $group->getKey() : $value,
                $condition['value'],
            );
        }

        return $condition;
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    private function userWith(Permission $permission): User
    {
        $user = User::factory()->create();
        // Opening an assistant's console needs ManageAssistants; the contact permission is what the screen asks on top.
        $user->givePermissionTo([Permission::ManageAssistants->value, $permission->value]);
        $user->assistants()->attach($this->assistant);

        return $user;
    }
}
