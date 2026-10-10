<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Database\Seeders\TenantAclSeeder;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\Uid\Ulid;

/**
 * The flow log on the Inertia console: every read bounded in time (the list by its period, an entry by the time its id
 * carries), scoped to the assistant's sessions, its filters, an entry's page, and who may open them.
 */
final class FlowLogsConsoleTest extends InertiaConsoleTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private Assistant $assistant;

    private FlowSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());

        $this->assistant = Assistant::factory()->create();
        $this->session   = $this->sessionOf($this->assistant);
    }

    public function test_the_list_covers_the_last_day_of_the_assistants_entries_by_default(): void
    {
        $recent = $this->entry(CarbonImmutable::now()->subHour(), ['node_id' => 'greet', 'source_handle' => 'next']);
        $this->entry(CarbonImmutable::now()->subDays(3));
        $this->entry(CarbonImmutable::now()->subMinutes(5), session: $this->sessionOf(Assistant::factory()->create()));

        $this->actingAs($this->admin())
            ->get($this->listUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/FlowLogs/Index')
                ->where('table.meta.total', 1)
                ->where('table.state', ['search' => '', 'sort' => '-created_at', 'perPage' => 25, 'filters' => []])
                ->where('table.defaults', ['sort' => '-created_at', 'perPage' => 25, 'perPageOptions' => [25, 50, 100, 250]])
                ->where('table.rows.0.id', (string) $recent->getKey())
                ->where('table.rows.0.status', 'executed')
                ->where('table.rows.0.nodeType', 'send_message')
                ->where('table.rows.0.nodeId', 'greet')
                ->where('table.rows.0.sourceHandle', 'next')
                ->where('table.rows.0.hasError', false)
                ->where('table.rows.0.sessionId', (string) $this->session->getKey())
                ->where('table.rows.0.viewUrl', "/assistant/{$this->assistant->getKey()}/flow-logs/{$recent->getKey()}")
                ->where('table.rows.0.sessionUrl', "/assistant/{$this->assistant->getKey()}/flow-sessions/{$this->session->getKey()}")
                ->where('period', '24h')
                ->where('periods', ['1h', '24h', '7d', '30d'])
                ->where('statuses', ['executed', 'failed', 'conflict', 'terminal'])
                ->where('live.event', 'flow.activity')
                ->where('nodeTypes', fn ($types): bool => in_array('send_message', $types->all(), true))
                ->etc());
    }

    public function test_a_longer_period_reaches_older_entries_and_an_unknown_one_keeps_the_default(): void
    {
        $this->entry(CarbonImmutable::now()->subHour());
        $this->entry(CarbonImmutable::now()->subDays(3));
        $this->entry(CarbonImmutable::now()->subDays(40));

        $this->actingAs($this->admin())
            ->get($this->listUrl('?filter[period]=7d'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 2)
                ->where('period', '7d')
                ->where('table.state.filters', ['period' => '7d'])
                ->etc());

        // Even the longest period is bounded: an entry older than 30 days is not read.
        $this->actingAs($this->admin())
            ->get($this->listUrl('?filter[period]=30d'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.total', 2)->etc());

        $this->actingAs($this->admin())
            ->get($this->listUrl('?filter[period]=forever'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('period', '24h')
                ->where('table.state.filters', [])
                ->etc());
    }

    public function test_the_list_filters_by_session_status_node_type_and_errors(): void
    {
        $other = $this->sessionOf($this->assistant);
        $now   = CarbonImmutable::now();

        $failed = $this->entry($now->subMinutes(3), ['status' => 'failed', 'node_type' => 'call', 'error' => ['message' => 'Timeout']]);
        $this->entry($now->subMinutes(2));
        $mine = $this->entry($now->subMinute(), session: $other);

        $this->actingAs($this->admin())
            ->get($this->listUrl("?filter[session]={$other->getKey()}"))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.id', (string) $mine->getKey())
                ->etc());

        foreach (['?filter[status]=failed', '?filter[errors]=1', '?filter[type]=call'] as $query) {
            $this->actingAs($this->admin())
                ->get($this->listUrl($query))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('table.meta.total', 1)
                    ->where('table.rows.0.id', (string) $failed->getKey())
                    ->where('table.rows.0.hasError', true)
                    ->etc());
        }

        // Values the screen does not know are no filter.
        $this->actingAs($this->admin())
            ->get($this->listUrl('?filter[status]=waiting&filter[type]=nope&filter[errors]=yes&filter[session]=x'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.total', 3)->where('table.state.filters', [])->etc());
    }

    public function test_an_entrys_page_shows_its_details(): void
    {
        $entry = $this->entry(CarbonImmutable::now()->subDays(20), [
            'status'        => 'failed',
            'state_changes' => ['flow.name' => 'Anna'],
            'resolved'      => ['text' => 'Hello, Anna', 'count' => 2],
            'error'         => ['message' => 'Timeout', 'retry' => true],
        ]);

        $this->actingAs($this->admin())
            ->get($this->viewUrl($entry))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/FlowLogs/Show')
                ->where('log.id', (string) $entry->getKey())
                ->where('log.status', 'failed')
                ->where('log.nodeType', 'send_message')
                ->where('log.nodeVersion', 1)
                ->where('stateChanges', [['key' => 'flow.name', 'value' => 'Anna']])
                ->where('resolved', [['key' => 'text', 'value' => 'Hello, Anna'], ['key' => 'count', 'value' => '2']])
                ->where('error', [['key' => 'message', 'value' => 'Timeout'], ['key' => 'retry', 'value' => 'true']])
                ->where('urls', [
                    'index'   => "/assistant/{$this->assistant->getKey()}/flow-logs",
                    'session' => "/assistant/{$this->assistant->getKey()}/flow-sessions/{$this->session->getKey()}",
                ])
                ->etc());
    }

    public function test_an_entry_is_found_only_near_the_time_its_id_carries(): void
    {
        $at    = CarbonImmutable::now()->subDays(2);
        $entry = $this->entry($at, id: (new Ulid(Ulid::generate($at->subHour())))->toRfc4122());

        $this->actingAs($this->admin())->get($this->viewUrl($entry))->assertNotFound();
    }

    public function test_another_assistants_entry_or_a_malformed_id_is_not_found(): void
    {
        $foreign = $this->entry(CarbonImmutable::now(), session: $this->sessionOf(Assistant::factory()->create()));

        $this->actingAs($this->admin())->get($this->viewUrl($foreign))->assertNotFound();
        $this->actingAs($this->admin())->get($this->listUrl('/not-a-uuid'))->assertNotFound();
    }

    public function test_the_screens_need_the_flow_sessions_permission(): void
    {
        $entry  = $this->entry(CarbonImmutable::now());
        $denied = $this->userWith(Permission::ManageFlow);

        $this->actingAs($denied)->get($this->listUrl())->assertForbidden();
        $this->actingAs($denied)->get($this->viewUrl($entry))->assertForbidden();

        $allowed = $this->userWith(Permission::ViewFlowSessions);

        $this->actingAs($allowed)->get($this->listUrl())->assertOk();
        $this->actingAs($allowed)->get($this->viewUrl($entry))->assertOk();
    }

    private function listUrl(string $suffix = ''): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/flow-logs{$suffix}");
    }

    private function viewUrl(FlowLog $entry): string
    {
        return $this->listUrl('/' . $entry->getKey());
    }

    private function sessionOf(Assistant $assistant): FlowSession
    {
        $flow       = FlowDraft::factory()->create(['assistant_id' => $assistant->getKey()]);
        $definition = FlowDefinition::query()->create([
            'tenant_id' => $flow->tenant_id,
            'flow_id'   => $flow->flow_id,
            'version'   => 1,
            'name'      => $flow->name,
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        return FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => Contact::factory()->forTenant(self::TENANT_ID)->create()->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'node-1',
            'state'              => [],
            'status'             => FlowSessionStatus::Active,
            'version'            => 0,
        ]);
    }

    /**
     * An entry as `FlowLogWriter` writes it: its ULID made in the same instant as `created_at`, unless `$id` says otherwise.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function entry(CarbonImmutable $at, array $attributes = [], ?FlowSession $session = null, ?string $id = null): FlowLog
    {
        return FlowLog::query()->create([
            'id'           => $id ?? (new Ulid(Ulid::generate($at)))->toRfc4122(),
            'session_id'   => ($session ?? $this->session)->getKey(),
            'node_id'      => 'node-1',
            'node_type'    => 'send_message',
            'node_version' => 1,
            'status'       => 'executed',
            'created_at'   => $at,
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
        $user->givePermissionTo([Permission::ManageAssistants->value, $permission->value]);
        $user->assistants()->attach($this->assistant);

        return $user;
    }
}
