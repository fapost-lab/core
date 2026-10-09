<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowGroup;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\SetsRecordLimits;

/**
 * Flows on the Inertia console: the list with its filters and grouping, the flow limit, creating a flow (which ends
 * in the builder), changing its metadata, switching it on and off, and deleting it with the guard for live sessions.
 */
final class FlowsConsoleTest extends InertiaConsoleTestCase
{
    use SetsRecordLimits;

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
     * @return array<string, array{0: FlowSessionStatus}>
     */
    public static function liveStatuses(): array
    {
        return [
            'pending'        => [FlowSessionStatus::Pending],
            'active'         => [FlowSessionStatus::Active],
            'waiting input'  => [FlowSessionStatus::WaitingInput],
            'paused'         => [FlowSessionStatus::Paused],
            'paused subflow' => [FlowSessionStatus::PausedSubflow],
        ];
    }

    public function test_the_list_shows_the_flows_of_the_assistant_with_their_details(): void
    {
        $group = $this->group('Support');
        $flow  = $this->flow('Help desk', ['flow_group_id' => $group->getKey(), 'is_public' => false, 'is_active' => true]);
        $this->publish($flow, 1, active: false);
        $this->publish($flow, 2, active: false);
        $this->publish($flow, 3);
        $this->trigger($flow, FlowTriggerType::Message, ['keywords' => ['help', 'support'], 'phrases' => ['reset password']]);

        $this->flow('Elsewhere', assistant: Assistant::factory()->create());
        $this->flow('Foreign', ['tenant_id' => self::OTHER_TENANT_ID], Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]));

        $this->actingAs($this->admin())
            ->get($this->listUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Flows/Index')
                ->where('table.meta.total', 1)
                ->where('table.state', ['search' => '', 'sort' => 'name', 'perPage' => 25, 'filters' => [], 'group' => ''])
                ->where('table.defaults', ['sort' => 'name', 'perPage' => 25, 'perPageOptions' => [25, 50, 100], 'group' => ''])
                ->where('table.rows.0', [
                    'id'               => (string) $flow->getKey(),
                    'flowId'           => $flow->flow_id,
                    'name'             => 'Help desk',
                    'isActive'         => true,
                    'isPublic'         => false,
                    'groupId'          => (string) $group->getKey(),
                    'groupName'        => 'Support',
                    'publishedVersion' => 3,
                    'trigger'          => ['type' => 'message', 'text' => 'help, support, reset password'],
                    'editUrl'          => "/assistant/{$this->assistant->getKey()}/flows/{$flow->getKey()}/edit",
                    'deleteUrl'        => "/assistant/{$this->assistant->getKey()}/flows/{$flow->getKey()}",
                    'activityUrl'      => "/assistant/{$this->assistant->getKey()}/flows/{$flow->getKey()}/active",
                    'builderUrl'       => "/builder/flows/{$flow->flow_id}",
                ])
                ->where('groups', [['id' => (string) $group->getKey(), 'name' => 'Support']])
                ->where('limit', ['reached' => false, 'hint' => null])
                ->where('can', ['create' => true, 'update' => true, 'delete' => true])
                ->where('urls.create', "/assistant/{$this->assistant->getKey()}/flows/create")
                ->etc());
    }

    public function test_a_flow_without_group_trigger_or_version_has_empty_details(): void
    {
        $this->flow('Plain');

        $this->actingAs($this->admin())
            ->get($this->listUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows.0.groupId', null)
                ->where('table.rows.0.groupName', null)
                ->where('table.rows.0.publishedVersion', null)
                ->where('table.rows.0.trigger', null)
                ->etc());
    }

    public function test_the_list_searches_by_name_and_sorts_by_name_or_group(): void
    {
        $alpha = $this->group('Alpha group');
        $beta  = $this->group('Beta group');
        $this->flow('Welcome', ['flow_group_id' => $beta->getKey()]);
        $this->flow('Billing', ['flow_group_id' => $alpha->getKey()]);
        $this->flow('Refunds', ['flow_group_id' => $alpha->getKey()]);

        $this->actingAs($this->admin());

        $this->get($this->listUrl('?search=WELC'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.total', 1)->where('table.rows.0.name', 'Welcome')->etc());

        $this->get($this->listUrl('?sort=-name'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.rows.0.name', 'Welcome')->etc());

        $this->get($this->listUrl('?sort=group_name'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows.0.groupName', 'Alpha group')
                ->where('table.rows.2.groupName', 'Beta group')
                ->etc());

        $this->get($this->listUrl('?sort=-group_name'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.rows.0.groupName', 'Beta group')->etc());

        $this->get($this->listUrl('?sort=tenant_id'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.state.sort', 'name')->etc());
    }

    public function test_the_list_filters_by_group_and_by_activity(): void
    {
        $group = $this->group('Sales');
        $this->flow('In group on', ['flow_group_id' => $group->getKey(), 'is_active' => true]);
        $this->flow('In group off', ['flow_group_id' => $group->getKey(), 'is_active' => false]);
        $this->flow('Loose on', ['is_active' => true]);

        $this->actingAs($this->admin());

        $this->get($this->listUrl('?filter[group]=' . $group->getKey()))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 2)
                ->where('table.state.filters', ['group' => (string) $group->getKey()])
                ->etc());

        $this->get($this->listUrl('?filter[active]=0'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.name', 'In group off')
                ->where('table.state.filters', ['active' => '0'])
                ->etc());

        $this->get($this->listUrl('?filter[active]=1&filter[group]=' . $group->getKey()))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.name', 'In group on')
                ->etc());
    }

    public function test_a_filter_with_a_value_it_does_not_accept_is_ignored_and_not_echoed(): void
    {
        $this->flow('One');
        $this->flow('Two', ['is_active' => false]);

        $this->actingAs($this->admin())
            ->get($this->listUrl('?filter[active]=maybe&filter[group]=nope&filter[secret]=1&group=nonsense'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 2)
                ->where('table.state.filters', [])
                ->where('table.state.group', '')
                ->etc());
    }

    public function test_a_group_of_another_assistant_is_no_filter(): void
    {
        $other = $this->group('Elsewhere', Assistant::factory()->create());
        $this->flow('Mine');

        $this->actingAs($this->admin())
            ->get($this->listUrl('?filter[group]=' . $other->getKey()))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.total', 0)->etc());
    }

    public function test_grouping_gathers_the_flows_of_a_group_and_puts_the_ungrouped_last(): void
    {
        $alpha = $this->group('Alpha');
        $beta  = $this->group('Beta');
        $this->flow('A loose flow');
        $this->flow('B in beta', ['flow_group_id' => $beta->getKey()]);
        $this->flow('C in alpha', ['flow_group_id' => $alpha->getKey()]);
        $this->flow('D in beta', ['flow_group_id' => $beta->getKey()]);

        $this->actingAs($this->admin())
            ->get($this->listUrl('?group=group'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.state.group', 'group')
                ->where('table.rows.0.name', 'C in alpha')
                ->where('table.rows.1.name', 'B in beta')
                ->where('table.rows.2.name', 'D in beta')
                ->where('table.rows.3.name', 'A loose flow')
                ->etc());
    }

    public function test_a_flow_whose_group_is_gone_counts_as_having_no_group(): void
    {
        $alpha = $this->group('Alpha');
        $gone  = $this->group('Gone');
        $this->flow('A loose flow');
        $this->flow('B orphan', ['flow_group_id' => $gone->getKey()]);
        $this->flow('C in alpha', ['flow_group_id' => $alpha->getKey()]);
        $this->flow('D orphan', ['flow_group_id' => $gone->getKey()]);
        // The column has no foreign key, so the group can vanish under its flows.
        $gone->delete();

        $this->actingAs($this->admin())
            ->get($this->listUrl('?group=group'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows.0.name', 'C in alpha')
                ->where('table.rows.1.name', 'A loose flow')
                ->where('table.rows.2.name', 'B orphan')
                ->where('table.rows.3.name', 'D orphan')
                ->where('table.rows.2.groupId', null)
                ->where('table.rows.2.groupName', null)
                ->where('table.rows.3.groupId', null)
                ->etc());
    }

    public function test_a_flow_whose_group_is_gone_opens_without_a_group_and_can_be_saved(): void
    {
        $gone = $this->group('Gone');
        $flow = $this->flow('Orphan', ['flow_group_id' => $gone->getKey()]);
        $gone->delete();

        $this->actingAs($this->admin())
            ->get($this->listUrl("/{$flow->getKey()}/edit"))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('flow.flowGroupId', null)->etc());

        $this->put($this->listUrl("/{$flow->getKey()}"), ['name' => 'Orphan', 'flow_group_id' => null, 'is_public' => true, 'logging_enabled' => false])
            ->assertSessionDoesntHaveErrors();

        $this->assertNull($flow->refresh()->flow_group_id);
    }

    public function test_the_list_is_paged(): void
    {
        foreach (range(1, 30) as $number) {
            $this->flow(sprintf('Flow %02d', $number));
        }

        $this->actingAs($this->admin())
            ->get($this->listUrl('?page=2'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta', ['total' => 30, 'perPage' => 25, 'currentPage' => 2, 'lastPage' => 2, 'from' => 26, 'to' => 30])
                ->etc());
    }

    public function test_at_the_limit_the_list_closes_creating_even_for_an_administrator(): void
    {
        $this->flow('Only');
        $this->limitRecords('flows', 1);

        $this->actingAs($this->admin());

        $this->get($this->listUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('limit', ['reached' => true, 'hint' => 'Limit reached (1 of 1)'])
                ->where('can.create', false)
                ->etc());

        $this->get($this->listUrl('/create'))->assertForbidden();
    }

    public function test_the_limit_counts_the_whole_tenant_not_just_this_assistant(): void
    {
        $this->flow('Elsewhere', assistant: Assistant::factory()->create());
        $this->limitRecords('flows', 1);

        $this->actingAs($this->admin())
            ->get($this->listUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('limit.reached', true)->where('can.create', false)->etc());
    }

    public function test_below_the_limit_creating_is_open(): void
    {
        $this->flow('Only');
        $this->limitRecords('flows', 2);

        $this->actingAs($this->admin());

        $this->get($this->listUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('limit', ['reached' => false, 'hint' => null])->where('can.create', true)->etc());

        $this->get($this->listUrl('/create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Flows/Create')
                ->where('can.createGroup', true)
                ->where('urls.submit', "/assistant/{$this->assistant->getKey()}/flows")
                ->where('urls.storeGroup', "/assistant/{$this->assistant->getKey()}/flow-groups/inline")
                ->etc());
    }

    public function test_a_flow_is_created_and_the_user_is_sent_to_the_builder(): void
    {
        $group = $this->group('Support');

        $response = $this->actingAs($this->admin())
            ->post($this->listUrl(), [
                'name'            => 'Welcome',
                'flow_group_id'   => $group->getKey(),
                'description'     => 'Greets people',
                'is_public'       => false,
                'logging_enabled' => true,
            ], ['X-Inertia' => 'true']);

        $flow = FlowDraft::query()->where('name', 'Welcome')->sole();

        $response->assertStatus(409)->assertHeader('X-Inertia-Location', "/builder/flows/{$flow->flow_id}");

        $this->assertSame(self::TENANT_ID, $flow->tenant_id);
        $this->assertSame((string) $this->assistant->getKey(), $flow->assistant_id);
        $this->assertSame((string) $group->getKey(), $flow->flow_group_id);
        $this->assertSame('Greets people', $flow->description);
        $this->assertFalse($flow->is_public);
        $this->assertTrue($flow->logging_enabled);
        $this->assertTrue($flow->is_active);
        $this->assertSame('end', $flow->nodes[0]['type']);
    }

    public function test_creating_without_the_optional_fields(): void
    {
        $this->actingAs($this->admin())
            ->post($this->listUrl(), ['name' => 'Bare', 'is_public' => true, 'logging_enabled' => false])
            ->assertRedirect();

        $flow = FlowDraft::query()->where('name', 'Bare')->sole();

        $this->assertNull($flow->flow_group_id);
        $this->assertNull($flow->description);
        $this->assertFalse($flow->logging_enabled);
    }

    public function test_creating_validates_the_fields(): void
    {
        $foreignGroup = $this->group('Elsewhere', Assistant::factory()->create());
        $otherTenant  = $this->group('Foreign', Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]), self::OTHER_TENANT_ID);
        $valid        = ['name' => 'Ok', 'is_public' => true, 'logging_enabled' => false];

        $this->actingAs($this->admin());

        $this->post($this->listUrl(), [...$valid, 'name' => ''])->assertSessionHasErrors('name');
        $this->post($this->listUrl(), [...$valid, 'name' => str_repeat('a', 256)])->assertSessionHasErrors('name');
        $this->post($this->listUrl(), [...$valid, 'description' => str_repeat('a', 65536)])->assertSessionHasErrors('description');
        $this->post($this->listUrl(), [...$valid, 'flow_group_id' => 'not-an-id'])->assertSessionHasErrors('flow_group_id');
        $this->post($this->listUrl(), [...$valid, 'flow_group_id' => (string) Str::uuid()])->assertSessionHasErrors('flow_group_id');
        $this->post($this->listUrl(), [...$valid, 'flow_group_id' => $foreignGroup->getKey()])->assertSessionHasErrors('flow_group_id');
        $this->post($this->listUrl(), [...$valid, 'flow_group_id' => $otherTenant->getKey()])->assertSessionHasErrors('flow_group_id');
        $this->post($this->listUrl(), ['name' => 'Ok'])->assertSessionHasErrors(['is_public', 'logging_enabled']);

        $this->assertSame(0, FlowDraft::query()->count());
    }

    public function test_a_create_that_lost_the_race_for_the_last_slot_is_refused_with_a_message(): void
    {
        $this->limitRecords('flows', 0);
        $from = $this->listUrl('/create');

        $this->actingAs($this->admin())
            ->from($from)
            ->post($this->listUrl(), ['name' => 'Late', 'is_public' => true, 'logging_enabled' => false])
            ->assertRedirect($from)
            ->assertInertiaFlash('error', 'Flow limit reached. Flows limit reached: 0 of 0.');

        $this->assertSame(0, FlowDraft::query()->count());
    }

    public function test_the_edit_screen_shows_the_metadata_and_the_groups_of_the_assistant(): void
    {
        $group = $this->group('Support');
        $this->group('Elsewhere', Assistant::factory()->create());
        $flow = $this->flow('Help', ['flow_group_id' => $group->getKey(), 'description' => 'Text', 'is_public' => false, 'logging_enabled' => true]);

        $this->actingAs($this->admin())
            ->get($this->listUrl("/{$flow->getKey()}/edit"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Flows/Edit')
                ->where('flow', [
                    'id'             => (string) $flow->getKey(),
                    'name'           => 'Help',
                    'flowGroupId'    => (string) $group->getKey(),
                    'description'    => 'Text',
                    'isPublic'       => false,
                    'loggingEnabled' => true,
                ])
                ->where('groups', [['id' => (string) $group->getKey(), 'name' => 'Support']])
                ->where('urls.submit', "/assistant/{$this->assistant->getKey()}/flows/{$flow->getKey()}")
                ->etc());
    }

    public function test_changing_a_flow_touches_its_metadata_and_never_its_graph(): void
    {
        $group = $this->group('Support');
        $flow  = $this->flow('Old', ['nodes' => [['id' => 'n1', 'type' => 'end']], 'edges' => [['from' => 'a', 'to' => 'b']], 'draft_version' => 7, 'is_active' => false]);

        $this->actingAs($this->admin())
            ->put($this->listUrl("/{$flow->getKey()}"), [
                'name'            => 'New',
                'flow_group_id'   => $group->getKey(),
                'description'     => null,
                'is_public'       => false,
                'logging_enabled' => true,
                'nodes'           => [],
                'is_active'       => true,
                'draft_version'   => 1,
            ])
            ->assertRedirect($this->listUrl())
            ->assertInertiaFlash('success');

        $flow->refresh();

        $this->assertSame('New', $flow->name);
        $this->assertSame((string) $group->getKey(), $flow->flow_group_id);
        $this->assertFalse($flow->is_public);
        $this->assertTrue($flow->logging_enabled);
        $this->assertSame([['id' => 'n1', 'type' => 'end']], $flow->nodes);
        $this->assertSame([['from' => 'a', 'to' => 'b']], $flow->edges);
        $this->assertSame(7, $flow->draft_version);
        $this->assertFalse($flow->is_active);
    }

    public function test_a_flow_can_be_taken_out_of_its_group(): void
    {
        $flow = $this->flow('Grouped', ['flow_group_id' => $this->group('Support')->getKey()]);

        $this->actingAs($this->admin())
            ->put($this->listUrl("/{$flow->getKey()}"), ['name' => 'Grouped', 'flow_group_id' => null, 'is_public' => true, 'logging_enabled' => false])
            ->assertSessionDoesntHaveErrors();

        $this->assertNull($flow->refresh()->flow_group_id);
    }

    public function test_a_flow_is_switched_on_and_off_by_the_state_asked_for(): void
    {
        $flow = $this->flow('Switch', ['is_active' => true]);
        $url  = $this->listUrl("/{$flow->getKey()}/active");
        $from = $this->listUrl('?filter[active]=1');

        $this->actingAs($this->admin());

        $this->from($from)->patch($url, ['active' => false])->assertRedirect($from)->assertInertiaFlash('success');
        $this->assertFalse($flow->refresh()->is_active);

        // Asking again for the same state changes nothing.
        $this->from($from)->patch($url, ['active' => false])->assertRedirect($from);
        $this->assertFalse($flow->refresh()->is_active);

        $this->patch($url, ['active' => true])->assertInertiaFlash('success');
        $this->assertTrue($flow->refresh()->is_active);

        $this->patch($url, [])->assertSessionHasErrors('active');
        $this->patch($url, ['active' => 'maybe'])->assertSessionHasErrors('active');
    }

    public function test_a_flow_without_sessions_is_deleted(): void
    {
        $flow = $this->flow('Doomed');
        $keep = $this->flow('Kept');
        $url  = $this->listUrl('?search=d&page=2');

        $this->actingAs($this->admin())
            ->from($url)
            ->delete($this->listUrl("/{$flow->getKey()}"))
            ->assertRedirect($url)
            ->assertInertiaFlash('success');

        $this->assertModelMissing($flow);
        $this->assertModelExists($keep);
    }

    #[DataProvider('liveStatuses')]
    public function test_a_flow_with_a_live_session_is_not_deleted(FlowSessionStatus $status): void
    {
        $flow = $this->flow('Busy');
        // The session runs an older published version, not the active one: any version holds the flow.
        $old = $this->publish($flow, 1, active: false);
        $this->publish($flow, 2);
        $this->sessionOn($old, $status);

        $this->actingAs($this->admin())
            ->delete($this->listUrl("/{$flow->getKey()}"))
            ->assertInertiaFlash('error');

        $this->assertModelExists($flow);
    }

    public function test_finished_sessions_do_not_hold_a_flow_back(): void
    {
        $flow       = $this->flow('Done');
        $definition = $this->publish($flow, 1);

        foreach ([FlowSessionStatus::Completed, FlowSessionStatus::Ended, FlowSessionStatus::Failed, FlowSessionStatus::Cancelled, FlowSessionStatus::Expired, FlowSessionStatus::TerminatedByUser] as $status) {
            $this->sessionOn($definition, $status);
        }

        $this->actingAs($this->admin())
            ->delete($this->listUrl("/{$flow->getKey()}"))
            ->assertInertiaFlash('success');

        $this->assertModelMissing($flow);
    }

    public function test_a_live_session_of_another_flow_does_not_matter(): void
    {
        $flow  = $this->flow('Free');
        $other = $this->flow('Busy');
        $this->sessionOn($this->publish($other, 1), FlowSessionStatus::Active);

        $this->actingAs($this->admin())
            ->delete($this->listUrl("/{$flow->getKey()}"))
            ->assertInertiaFlash('success');

        $this->assertModelMissing($flow);
        $this->assertModelExists($other);
    }

    public function test_many_flows_are_deleted_one_by_one_and_the_ones_with_live_sessions_are_skipped(): void
    {
        $free    = $this->flow('Free');
        $busy    = $this->flow('Busy');
        $foreign = $this->flow('Elsewhere', assistant: Assistant::factory()->create());
        $this->sessionOn($this->publish($busy, 1), FlowSessionStatus::WaitingInput);

        $this->actingAs($this->admin())
            ->delete($this->listUrl(), ['ids' => [$free->getKey(), $busy->getKey(), $foreign->getKey()]])
            ->assertInertiaFlash('success', 'Flows deleted: 1. Skipped because they have active sessions: 1.');

        $this->assertModelMissing($free);
        $this->assertModelExists($busy);
        $this->assertModelExists($foreign);
    }

    public function test_deleting_only_flows_with_live_sessions_is_an_error(): void
    {
        $busy = $this->flow('Busy');
        $this->sessionOn($this->publish($busy, 1), FlowSessionStatus::Active);

        $this->actingAs($this->admin())
            ->delete($this->listUrl(), ['ids' => [$busy->getKey()]])
            ->assertInertiaFlash('error');

        $this->assertModelExists($busy);
    }

    public function test_deleting_many_validates_the_ids(): void
    {
        $this->actingAs($this->admin());

        $this->delete($this->listUrl(), [])->assertSessionHasErrors('ids');
        $this->delete($this->listUrl(), ['ids' => ['not-an-id']])->assertSessionHasErrors('ids.0');
        $this->delete($this->listUrl(), ['ids' => array_fill(0, 101, '00000000-0000-0000-0000-000000000002')])->assertSessionHasErrors('ids');
    }

    public function test_a_flow_of_another_assistant_or_tenant_is_not_found(): void
    {
        $other   = $this->flow('Other assistant', assistant: Assistant::factory()->create());
        $foreign = $this->flow('Foreign', ['tenant_id' => self::OTHER_TENANT_ID], Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]));
        $payload = ['name' => 'Hijacked', 'is_public' => true, 'logging_enabled' => false];

        $this->actingAs($this->admin());

        foreach ([$other, $foreign] as $flow) {
            $this->get($this->listUrl("/{$flow->getKey()}/edit"))->assertNotFound();
            $this->put($this->listUrl("/{$flow->getKey()}"), $payload)->assertNotFound();
            $this->patch($this->listUrl("/{$flow->getKey()}/active"), ['active' => false])->assertNotFound();
            $this->delete($this->listUrl("/{$flow->getKey()}"))->assertNotFound();
        }

        $this->get($this->listUrl('/not-an-id/edit'))->assertNotFound();
        $this->assertDatabaseMissing('flow_drafts', ['name' => 'Hijacked']);
        $this->assertTrue($other->refresh()->is_active);
    }

    public function test_a_user_without_the_flow_permission_is_refused_everywhere(): void
    {
        $flow    = $this->flow('Hidden');
        $payload = ['name' => 'New', 'is_public' => true, 'logging_enabled' => false];

        $this->actingAs($this->userWith(Permission::ManageFlowGroups));

        $this->get($this->listUrl())->assertForbidden();
        $this->get($this->listUrl('/create'))->assertForbidden();
        $this->post($this->listUrl(), $payload)->assertForbidden();
        $this->get($this->listUrl("/{$flow->getKey()}/edit"))->assertForbidden();
        $this->put($this->listUrl("/{$flow->getKey()}"), $payload)->assertForbidden();
        $this->patch($this->listUrl("/{$flow->getKey()}/active"), ['active' => false])->assertForbidden();
        $this->delete($this->listUrl("/{$flow->getKey()}"))->assertForbidden();
        $this->delete($this->listUrl(), ['ids' => [$flow->getKey()]])->assertForbidden();

        $this->assertDatabaseHas('flow_drafts', ['id' => $flow->getKey(), 'name' => 'Hidden', 'is_active' => true]);
        $this->assertSame(1, FlowDraft::query()->count());
    }

    public function test_a_flow_manager_may_write_but_not_create_groups_without_that_permission(): void
    {
        $this->flow('Mine');
        $user = $this->userWith(Permission::ManageFlowDefinitions);

        $this->actingAs($user);

        $this->get($this->listUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can', ['create' => true, 'update' => true, 'delete' => true])
                ->etc());

        $this->get($this->listUrl('/create'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.createGroup', false)->etc());

        $user->givePermissionTo(Permission::ManageFlowGroups->value);

        $this->get($this->listUrl('/create'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.createGroup', true)->etc());
    }

    public function test_a_manager_cannot_reach_the_flows_of_an_assistant_they_are_not_assigned_to(): void
    {
        $unassigned = Assistant::factory()->create();
        $flow       = $this->flow('Theirs', assistant: $unassigned);

        $this->actingAs($this->userWith(Permission::ManageFlowDefinitions));

        // The console stack does not even admit the user to an assistant that is not theirs.
        $this->get($this->panelUrl("/assistant/{$unassigned->getKey()}/flows"))->assertNotFound();
        $this->delete($this->panelUrl("/assistant/{$unassigned->getKey()}/flows/{$flow->getKey()}"))->assertNotFound();
        $this->assertModelExists($flow);
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->get($this->listUrl())->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_the_builder_sends_the_user_back_to_the_console_list(): void
    {
        $flow = $this->flow('Round trip');

        $this->actingAs($this->admin())
            ->get("/builder/flows/{$flow->flow_id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('backUrl', route('filament.assistant.resources.flows.index', ['tenant' => $this->assistant->getKey()]))
                ->etc());
    }

    private function listUrl(string $suffix = ''): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/flows{$suffix}");
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function flow(string $name, array $attributes = [], ?Assistant $assistant = null): FlowDraft
    {
        return FlowDraft::factory()->create([
            'assistant_id' => ($assistant ?? $this->assistant)->getKey(),
            'name'         => $name,
            ...$attributes,
        ]);
    }

    private function group(string $name, ?Assistant $assistant = null, string $tenantId = self::TENANT_ID): FlowGroup
    {
        return FlowGroup::query()->create([
            'tenant_id'    => $tenantId,
            'assistant_id' => ($assistant ?? $this->assistant)->getKey(),
            'name'         => $name,
        ]);
    }

    private function publish(FlowDraft $flow, int $version, bool $active = true): FlowDefinition
    {
        return FlowDefinition::query()->create([
            'tenant_id' => $flow->tenant_id,
            'flow_id'   => $flow->flow_id,
            'version'   => $version,
            'name'      => $flow->name,
            'nodes'     => [],
            'edges'     => [],
            'is_active' => $active,
        ]);
    }

    private function sessionOn(FlowDefinition $definition, FlowSessionStatus $status): FlowSession
    {
        return FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $this->assistant->getKey(),
            'contact_id'         => Contact::factory()->forTenant(self::TENANT_ID)->create()->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => $definition->version,
            'current_node_id'    => 'node-1',
            'state'              => [],
            'status'             => $status,
            'version'            => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function trigger(FlowDraft $flow, FlowTriggerType $type, array $config): FlowTrigger
    {
        return FlowTrigger::query()->create([
            'tenant_id'    => $flow->tenant_id,
            'assistant_id' => $flow->assistant_id,
            'flow_id'      => $flow->flow_id,
            'type'         => $type,
            'is_active'    => true,
            'priority'     => 100,
            'config'       => $config,
        ]);
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
        // Opening an assistant's console needs ManageAssistants; the flow permission is what the screen asks on top.
        $user->givePermissionTo([Permission::ManageAssistants->value, $permission->value]);
        $user->assistants()->attach($this->assistant);

        return $user;
    }
}
