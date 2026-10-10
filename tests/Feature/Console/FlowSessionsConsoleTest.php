<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Live\FlowActivityWatchers;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowSessionHistoryEntry;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Flow\History\HistoryEventType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * Flow sessions on the Inertia console: which sessions an assistant sees, the live default and the other filters, a
 * session's page, and who may open them.
 */
final class FlowSessionsConsoleTest extends InertiaConsoleTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string OTHER_TENANT_ID = '00000000-0000-0000-0000-0000000000ff';

    private Assistant $assistant;

    private FlowDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());

        $this->assistant  = Assistant::factory()->create();
        $this->definition = $this->publish($this->flow('Help desk'));
    }

    public function test_the_list_shows_the_live_sessions_of_the_assistant_by_default(): void
    {
        $live = $this->sessionWith(FlowSessionStatus::WaitingInput, ['current_node_id' => 'ask-name'], externalId: '1001');
        $this->sessionWith(FlowSessionStatus::Completed);

        $sibling = Assistant::factory()->create();
        $this->sessionWith(FlowSessionStatus::Active, assistant: $sibling);

        $foreign = Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]);
        $this->sessionWith(FlowSessionStatus::Active, ['tenant_id' => self::OTHER_TENANT_ID], assistant: $foreign);

        $this->actingAs($this->admin())
            ->get($this->listUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/FlowSessions/Index')
                ->where('table.meta.total', 1)
                ->where('table.state', ['search' => '', 'sort' => '-updated_at', 'perPage' => 25, 'filters' => []])
                ->where('table.rows.0.id', (string) $live->getKey())
                ->where('table.rows.0.shortId', mb_substr((string) $live->getKey(), 0, 8))
                ->where('table.rows.0.status', 'waiting_input')
                ->where('table.rows.0.endStatus', null)
                ->where('table.rows.0.contactExternalId', '1001')
                ->where('table.rows.0.flowName', 'Help desk')
                ->where('table.rows.0.currentNodeId', 'ask-name')
                ->where('table.rows.0.viewUrl', "/assistant/{$this->assistant->getKey()}/flow-sessions/{$live->getKey()}")
                ->where('flows', [['value' => (string) $this->definition->flow_id, 'label' => 'Help desk']])
                ->where('periods', ['1h', '24h', '7d', '30d'])
                ->where('live', [
                    'channel' => 'tenant.' . self::TENANT_ID . ".assistant.{$this->assistant->getKey()}.flow",
                    'event'   => 'flow.activity',
                ])
                ->where('urls', ['index' => "/assistant/{$this->assistant->getKey()}/flow-sessions"])
                ->has('statuses', 11)
                ->etc());
    }

    public function test_the_status_filter_lists_every_status_or_one(): void
    {
        $this->sessionWith(FlowSessionStatus::Active);
        $completed = $this->sessionWith(FlowSessionStatus::Completed, ['end_status' => 'success']);

        $this->actingAs($this->admin())
            ->get($this->listUrl('?filter[status]=all'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 2)
                ->where('table.state.filters', ['status' => 'all'])
                ->etc());

        $this->actingAs($this->admin())
            ->get($this->listUrl('?filter[status]=completed'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.id', (string) $completed->getKey())
                ->where('table.rows.0.endStatus', 'success')
                ->etc());

        // An unknown value is no filter: the list stays on the live sessions.
        $this->actingAs($this->admin())
            ->get($this->listUrl('?filter[status]=bogus'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.state.filters', [])
                ->etc());
    }

    public function test_the_list_searches_the_contact_and_filters_by_flow_and_start(): void
    {
        $wanted = $this->sessionWith(FlowSessionStatus::Active, externalId: 'alice-42');
        $this->sessionWith(FlowSessionStatus::Active, externalId: 'bob-7');

        $other      = $this->publish($this->flow('Onboarding'));
        $otherFlows = $this->sessionWith(FlowSessionStatus::Active, definition: $other);

        $old = $this->sessionWith(FlowSessionStatus::Active);
        FlowSession::query()->whereKey($old->getKey())->update(['created_at' => Carbon::now()->subDays(3)]);

        $this->actingAs($this->admin())
            ->get($this->listUrl('?search=ALICE'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.id', (string) $wanted->getKey())
                ->etc());

        // An exact contact id finds the contact's sessions too.
        $this->actingAs($this->admin())
            ->get($this->listUrl('?search=' . $wanted->contact_id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.id', (string) $wanted->getKey())
                ->etc());

        $this->actingAs($this->admin())
            ->get($this->listUrl("?filter[flow]={$other->flow_id}"))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.id', (string) $otherFlows->getKey())
                ->etc());

        // Another assistant's flow is no filter.
        $foreignFlow = $this->flow('Elsewhere', Assistant::factory()->create());
        $this->actingAs($this->admin())
            ->get($this->listUrl("?filter[flow]={$foreignFlow->flow_id}"))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.total', 4)->where('table.state.filters', [])->etc());

        $this->actingAs($this->admin())
            ->get($this->listUrl('?filter[period]=24h'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 3)
                ->where('table.state.filters', ['period' => '24h'])
                ->etc());
    }

    public function test_a_sessions_page_shows_its_details_state_and_links(): void
    {
        $parent  = $this->sessionWith(FlowSessionStatus::PausedSubflow);
        $session = $this->sessionWith(FlowSessionStatus::Active, [
            'current_node_id'       => 'ask-email',
            'parent_session_id'     => $parent->getKey(),
            'parent_resume_node_id' => 'after-child',
            'state'                 => ['flow' => ['name' => 'Anna', 'tags' => ['a', 'b']], 'system' => ['language' => 'ru', 'count' => 3]],
        ], externalId: '1001');

        $this->actingAs($this->admin())
            ->get($this->viewUrl($session))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/FlowSessions/Show')
                ->where('session.id', (string) $session->getKey())
                ->where('session.status', 'active')
                ->where('session.currentNodeId', 'ask-email')
                ->where('session.flowName', 'Help desk')
                ->where('session.contactExternalId', '1001')
                ->where('session.parentId', (string) $parent->getKey())
                ->where('session.parentResumeNodeId', 'after-child')
                ->where('session.isLive', true)
                // Sorted by key: `jsonb` keeps no key order, so the written order is not what comes back.
                ->where('state', [
                    ['key' => 'flow.name', 'value' => 'Anna'],
                    ['key' => 'flow.tags', 'value' => '["a","b"]'],
                    ['key' => 'system.count', 'value' => '3'],
                    ['key' => 'system.language', 'value' => 'ru'],
                ])
                ->where('history', null)
                ->where('urls', [
                    'index'  => "/assistant/{$this->assistant->getKey()}/flow-sessions",
                    'parent' => "/assistant/{$this->assistant->getKey()}/flow-sessions/{$parent->getKey()}",
                    'logs'   => "/assistant/{$this->assistant->getKey()}/flow-logs?filter%5Bsession%5D={$session->getKey()}&filter%5Bperiod%5D=30d",
                ])
                ->has('live')
                ->etc());
    }

    public function test_a_sessions_page_shows_its_history_newest_first(): void
    {
        $session = $this->sessionWith(FlowSessionStatus::Completed);
        $this->history($session, 'first', Carbon::now()->subMinutes(5), ['new_value' => 'Anna', 'path' => 'flow.name']);
        $this->history($session, 'second', Carbon::now()->subMinute());

        $this->actingAs($this->admin())
            ->get($this->viewUrl($session))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('session.isLive', false)
                ->has('history', 2)
                ->where('history.0.nodeId', 'second')
                ->where('history.1.nodeId', 'first')
                ->where('history.1.path', 'flow.name')
                ->where('history.1.payload', 'new: Anna')
                ->etc());
    }

    public function test_another_assistants_session_or_a_malformed_id_is_not_found(): void
    {
        $sibling = $this->sessionWith(FlowSessionStatus::Active, assistant: Assistant::factory()->create());

        $this->actingAs($this->admin())->get($this->viewUrl($sibling))->assertNotFound();
        $this->actingAs($this->admin())->get($this->listUrl('/not-a-uuid'))->assertNotFound();
        $this->actingAs($this->admin())->get($this->listUrl('/' . Str::ulid()->toRfc4122()))->assertNotFound();
    }

    public function test_an_open_screen_marks_the_assistant_watched_only_when_a_broadcaster_delivers(): void
    {
        $watchers = $this->app->make(FlowActivityWatchers::class);
        $session  = $this->sessionWith(FlowSessionStatus::Active);

        $this->actingAs($this->admin())->get($this->listUrl())->assertOk();
        $this->assertFalse($watchers->isWatched(self::TENANT_ID, (string) $this->assistant->getKey()), 'polling: nothing to announce');

        config(['broadcasting.default' => 'pusher']);

        $this->actingAs($this->admin())->get($this->viewUrl($session))->assertOk();
        $this->assertTrue($watchers->isWatched(self::TENANT_ID, (string) $this->assistant->getKey()));
    }

    public function test_the_screens_need_the_flow_sessions_permission(): void
    {
        $session = $this->sessionWith(FlowSessionStatus::Active);
        $denied  = $this->userWith(Permission::ManageFlow);

        $this->actingAs($denied)->get($this->listUrl())->assertForbidden();
        $this->actingAs($denied)->get($this->viewUrl($session))->assertForbidden();

        $allowed = $this->userWith(Permission::ViewFlowSessions);

        $this->actingAs($allowed)->get($this->listUrl())->assertOk();
        $this->actingAs($allowed)
            ->get($this->viewUrl($session))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('urls.logs')->etc());
    }

    private function listUrl(string $suffix = ''): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/flow-sessions{$suffix}");
    }

    private function viewUrl(FlowSession $session): string
    {
        return $this->listUrl('/' . $session->getKey());
    }

    private function flow(string $name, ?Assistant $assistant = null): FlowDraft
    {
        return FlowDraft::factory()->create(['assistant_id' => ($assistant ?? $this->assistant)->getKey(), 'name' => $name]);
    }

    private function publish(FlowDraft $flow): FlowDefinition
    {
        return FlowDefinition::query()->create([
            'tenant_id' => $flow->tenant_id,
            'flow_id'   => $flow->flow_id,
            'version'   => 1,
            'name'      => $flow->name,
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function sessionWith(
        FlowSessionStatus $status,
        array $attributes = [],
        ?Assistant $assistant = null,
        ?FlowDefinition $definition = null,
        ?string $externalId = null,
    ): FlowSession {
        $definition ??= $this->definition;
        $tenantId = $attributes['tenant_id'] ?? self::TENANT_ID;

        return FlowSession::query()->create([
            'tenant_id'          => $tenantId,
            'assistant_id'       => ($assistant ?? $this->assistant)->getKey(),
            'contact_id'         => Contact::factory()->forTenant($tenantId)->create(null === $externalId ? [] : ['external_id' => $externalId])->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => $definition->version,
            'current_node_id'    => 'node-1',
            'state'              => [],
            'status'             => $status,
            'version'            => 0,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function history(FlowSession $session, string $nodeId, Carbon $at, array $attributes = []): void
    {
        FlowSessionHistoryEntry::query()->create([
            'tenant_id'  => self::TENANT_ID,
            'session_id' => $session->getKey(),
            'node_id'    => $nodeId,
            'event_type' => HistoryEventType::cases()[0],
            'created_at' => $at,
            ...$attributes,
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
        // Opening an assistant's console needs ManageAssistants; the sessions permission is what the screen asks on top.
        $user->givePermissionTo([Permission::ManageAssistants->value, $permission->value]);
        $user->assistants()->attach($this->assistant);

        return $user;
    }
}
