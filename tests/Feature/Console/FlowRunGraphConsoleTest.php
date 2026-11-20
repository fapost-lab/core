<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowSessionStatus;
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
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;

/**
 * The run graph of a flow session: the executed definition with what each node did, loop passes, subflow children as
 * frames, a node's visits — and never a node's input, output or error message.
 */
final class FlowRunGraphConsoleTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string SECRET = 'visible-only-to-run-payloads';

    private Assistant $assistant;

    private Carbon $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());

        $this->assistant = Assistant::factory()->create();
        $this->clock     = Carbon::now()->subHour();
    }

    public function test_the_graph_draws_the_executed_definition_with_what_each_node_did(): void
    {
        $definition = $this->publish('Orders', [
            ['id' => 'start', 'type' => 'send_message', 'version' => 1, 'label' => 'Greeting', 'config' => ['text' => 'secret text']],
            ['id' => 'check', 'type' => 'condition', 'version' => 1, 'config' => []],
            ['id' => 'yes', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ['id' => 'no', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'failed']],
        ], [
            ['id' => 'e1', 'from' => 'start', 'to' => 'check'],
            ['id' => 'e2', 'from' => 'check', 'to' => 'yes', 'handle' => 'rule-1'],
            ['from' => 'check', 'to' => 'no', 'handle' => 'else'],
        ]);
        $session = $this->sessionFor($definition, FlowSessionStatus::Completed);

        $this->trace($session, 'start', HistoryEventType::NodeEntered, ['type' => 'send_message']);
        $this->trace($session, 'start', HistoryEventType::NodeCompleted, [
            'type'  => 'send_message', 'status' => 'executed', 'handle' => 'default', 'next' => 'check', 'duration_ms' => 4.5,
            'input' => ['token' => self::SECRET], 'output' => ['body' => self::SECRET],
        ]);
        $this->trace($session, 'check', HistoryEventType::NodeEntered, ['type' => 'condition']);
        $this->trace($session, 'check', HistoryEventType::NodeCompleted, [
            'type'  => 'condition', 'status' => 'executed', 'handle' => 'rule-1', 'next' => 'yes', 'duration_ms' => 1,
            'error' => ['message' => self::SECRET],
        ]);
        $this->trace($session, 'yes', HistoryEventType::NodeEntered, ['type' => 'end']);
        $this->trace($session, 'yes', HistoryEventType::NodeFailed, [
            'type' => 'end', 'error' => ['class' => 'RuntimeException', 'message' => self::SECRET],
        ]);

        $response = $this->actingAs($this->admin())->get($this->graphUrl($session))->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Console/FlowSessions/Graph')
            ->where('session.id', (string) $session->getKey())
            ->where('session.flowName', 'Orders')
            ->where('session.isLive', false)
            ->where('graph.truncated', false)
            ->where('graph.limit', 20_000)
            ->where('graph.flow.sessionId', (string) $session->getKey())
            ->has('graph.flow.nodes', 4)
            ->where('graph.flow.nodes.0', [
                'id'        => 'start',
                'type'      => 'send_message',
                'version'   => 1,
                'label'     => 'Greeting',
                'typeLabel' => 'Send message',
                'endStatus' => null,
                'run'       => ['status' => 'done', 'visits' => 1, 'handles' => ['default'], 'durationMs' => 4.5, 'errorClass' => null],
            ])
            ->where('graph.flow.nodes.1.run.handles', ['rule-1'])
            ->where('graph.flow.nodes.2.endStatus', 'success')
            ->where('graph.flow.nodes.2.run.status', 'failed')
            ->where('graph.flow.nodes.2.run.errorClass', 'RuntimeException')
            ->where('graph.flow.nodes.3.endStatus', 'failed')
            ->where('graph.flow.nodes.3.run', null)
            ->where('graph.flow.edges', [
                ['id' => 'e1', 'from' => 'start', 'to' => 'check', 'handle' => 'default'],
                ['id' => 'e2', 'from' => 'check', 'to' => 'yes', 'handle' => 'rule-1'],
                ['id' => 'edge-2', 'from' => 'check', 'to' => 'no', 'handle' => 'else'],
            ])
            ->where('graph.flow.loops', [])
            ->where('graph.flow.children', [])
            ->where('urls.session', "/assistant/{$this->assistant->getKey()}/flow-sessions/{$session->getKey()}")
            ->where('urls.visits', "/assistant/{$this->assistant->getKey()}/flow-sessions/{$session->getKey()}/graph/visits")
            ->etc());

        // Neither a node's payloads, its error message nor its config reach the page.
        $this->assertStringNotContainsString(self::SECRET, (string) $response->getContent());
        $this->assertStringNotContainsString('secret text', (string) $response->getContent());
    }

    /**
     * Shaped as the engine writes it: the iterator is read from the state on entry, so the loop node's own re-entries
     * carry it too (passes 2..4 for a loop of 3, the last being the exit), and a later loop that reuses the iterator name
     * sees it set as well. Only a loop's body counts towards its passes.
     */
    public function test_a_loop_lists_each_pass_of_its_body_only(): void
    {
        $definition = $this->publish('Rows', [
            ['id' => 'loop', 'type' => 'loop', 'version' => 1, 'config' => []],
            ['id' => 'body', 'type' => 'assign', 'version' => 1, 'config' => []],
            ['id' => 'back', 'type' => 'loop_end', 'version' => 1, 'config' => []],
            ['id' => 'second', 'type' => 'loop', 'version' => 1, 'config' => []],
            ['id' => 'inner', 'type' => 'assign', 'version' => 1, 'config' => []],
        ], [
            ['from' => 'loop', 'to' => 'body', 'handle' => 'loop'],
            ['from' => 'body', 'to' => 'back'],
            ['from' => 'loop', 'to' => 'second'],
            ['from' => 'second', 'to' => 'inner', 'handle' => 'loop'],
        ]);
        $session = $this->sessionFor($definition, FlowSessionStatus::Failed);

        $this->trace($session, 'loop', HistoryEventType::NodeEntered, ['type' => 'loop']);
        $this->trace($session, 'loop', HistoryEventType::NodeCompleted, ['type' => 'loop', 'status' => 'executed', 'handle' => 'loop']);

        foreach ([1, 2, 3] as $pass) {
            $iterations = ['iterations' => ['loop' => $pass]];
            $this->trace($session, 'body', HistoryEventType::NodeEntered, ['type' => 'assign', ...$iterations]);
            $this->trace($session, 'body', HistoryEventType::NodeCompleted, ['type' => 'assign', 'status' => 'executed', 'handle' => 'default', ...$iterations]);
            $this->trace($session, 'back', HistoryEventType::NodeEntered, ['type' => 'loop_end', ...$iterations]);
            $this->trace($session, 'back', HistoryEventType::NodeCompleted, ['type' => 'loop_end', 'status' => 'executed', ...$iterations]);
            $next = ['iterations' => ['loop' => $pass + 1]];
            $this->trace($session, 'loop', HistoryEventType::NodeEntered, ['type' => 'loop', ...$next]);
            $this->trace($session, 'loop', HistoryEventType::NodeCompleted, ['type' => 'loop', 'status' => 'executed', 'handle' => 3 === $pass ? 'default' : 'loop', ...$next]);
        }

        // The second loop shares the iterator name: its body carries both loops' iterators.
        $shared = ['iterations' => ['loop' => 1, 'second' => 1]];
        $this->trace($session, 'inner', HistoryEventType::NodeEntered, ['type' => 'assign', ...$shared]);
        $this->trace($session, 'inner', HistoryEventType::NodeCompleted, ['type' => 'assign', 'status' => 'failed', 'handle' => 'default', ...$shared]);

        $body = static fn (string $status): array => [
            ['id' => 'body', 'status' => $status, 'handle' => 'default'],
            ['id' => 'back', 'status' => $status, 'handle' => null],
        ];

        $this->actingAs($this->admin())
            ->get($this->graphUrl($session))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('graph.flow.nodes.0.run.status', 'done')
                ->where('graph.flow.nodes.0.run.handles', ['loop', 'default'])
                ->where('graph.flow.nodes.1.run.visits', 3)
                ->where('graph.flow.loops', [
                    [
                        'nodeId' => 'loop',
                        'passes' => [
                            ['pass' => 1, 'nodes' => $body('done')],
                            ['pass' => 2, 'nodes' => $body('done')],
                            ['pass' => 3, 'nodes' => $body('done')],
                        ],
                    ],
                    [
                        'nodeId' => 'second',
                        'passes' => [['pass' => 1, 'nodes' => [['id' => 'inner', 'status' => 'failed', 'handle' => 'default']]]],
                    ],
                ])
                ->etc());
    }

    public function test_a_node_that_waits_and_resumes_is_one_visit(): void
    {
        $definition = $this->publish('Ask', [['id' => 'ask', 'type' => 'input', 'version' => 1, 'config' => []]], []);
        $session    = $this->sessionFor($definition, FlowSessionStatus::Completed);

        $this->trace($session, 'ask', HistoryEventType::NodeEntered, ['type' => 'input']);
        $this->trace($session, 'ask', HistoryEventType::NodeCompleted, ['type' => 'input', 'status' => 'waiting']);
        $this->trace($session, 'ask', HistoryEventType::NodeEntered, ['type' => 'input']);
        $this->trace($session, 'ask', HistoryEventType::NodeCompleted, ['type' => 'input', 'status' => 'executed', 'handle' => 'default']);
        $this->trace($session, 'ask', HistoryEventType::NodeEntered, ['type' => 'input']);

        $this->actingAs($this->admin())
            ->get($this->graphUrl($session))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('graph.flow.nodes.0.run.visits', 2)
                ->where('graph.flow.nodes.0.run.status', 'running')
                ->etc());
    }

    public function test_a_subflow_node_takes_the_exit_its_finished_child_maps_to(): void
    {
        $parentDefinition = $this->publish('Parent', [
            ['id' => 'call-child', 'type' => 'subflow', 'version' => 1, 'config' => []],
            ['id' => 'done', 'type' => 'end', 'version' => 1, 'config' => []],
        ], [['from' => 'call-child', 'to' => 'done', 'handle' => 'cancelled']]);
        $childDefinition = $this->publish('Child', [['id' => 'inner', 'type' => 'end', 'version' => 1, 'config' => []]], []);
        $parent          = $this->sessionFor($parentDefinition, FlowSessionStatus::Completed);
        $this->sessionFor($childDefinition, FlowSessionStatus::Completed, [
            'parent_session_id' => $parent->getKey(), 'parent_resume_node_id' => 'call-child', 'end_status' => 'cancelled',
        ]);
        $this->trace($parent, 'call-child', HistoryEventType::NodeEntered, ['type' => 'subflow']);
        $this->trace($parent, 'call-child', HistoryEventType::NodeCompleted, ['type' => 'subflow', 'status' => 'waiting']);

        $this->actingAs($this->admin())
            ->get($this->graphUrl($parent))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('graph.flow.nodes.0.run.status', 'done')
                ->where('graph.flow.nodes.0.run.handles', ['cancelled'])
                ->etc());
    }

    public function test_a_subflow_child_is_drawn_inside_the_node_that_started_it(): void
    {
        $parentDefinition = $this->publish('Parent', [['id' => 'call-child', 'type' => 'subflow', 'version' => 1, 'config' => []]], []);
        $childDefinition  = $this->publish('Child', [['id' => 'inner', 'type' => 'end', 'version' => 1, 'config' => []]], []);
        $parent           = $this->sessionFor($parentDefinition, FlowSessionStatus::PausedSubflow);
        $this->sessionFor($childDefinition, FlowSessionStatus::Completed, ['parent_session_id' => $parent->getKey(), 'parent_resume_node_id' => 'call-child']);
        $latest = $this->sessionFor($childDefinition, FlowSessionStatus::Active, [
            'parent_session_id' => $parent->getKey(), 'parent_resume_node_id' => 'call-child', 'created_at' => Carbon::now()->addMinute(),
        ]);
        $this->trace($latest, 'inner', HistoryEventType::NodeEntered, ['type' => 'end']);

        $this->actingAs($this->admin())
            ->get($this->graphUrl($parent))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('session.isLive', true)
                ->has('graph.flow.children', 1)
                ->where('graph.flow.children.0.nodeId', 'call-child')
                ->where('graph.flow.children.0.count', 2)
                ->where('graph.flow.children.0.flow.sessionId', (string) $latest->getKey())
                ->where('graph.flow.children.0.flow.flowName', 'Child')
                ->where('graph.flow.children.0.flow.nodes.0.run.status', 'running')
                ->etc());
    }

    public function test_a_nodes_visits_are_statuses_only_and_only_for_sessions_of_the_run(): void
    {
        $definition = $this->publish('Calls', [['id' => 'call', 'type' => 'call', 'version' => 1, 'config' => []]], []);
        $session    = $this->sessionFor($definition, FlowSessionStatus::Completed);
        $child      = $this->sessionFor($definition, FlowSessionStatus::Completed, ['parent_session_id' => $session->getKey(), 'parent_resume_node_id' => 'call']);
        $stranger   = $this->sessionFor($definition, FlowSessionStatus::Completed);

        $this->trace($session, 'call', HistoryEventType::NodeEntered, ['type' => 'call', 'iterations' => ['loop' => 4]]);
        $this->trace($session, 'call', HistoryEventType::NodeCompleted, [
            'type'       => 'call', 'status' => 'executed', 'handle' => 'error', 'next' => null, 'duration_ms' => 120.25,
            'iterations' => ['loop' => 4], 'input' => ['url' => self::SECRET], 'error' => ['message' => self::SECRET],
        ]);
        $this->trace($child, 'call', HistoryEventType::NodeEntered, ['type' => 'call']);

        $response = $this->actingAs($this->admin())
            ->getJson($this->visitsUrl($session, $session, 'call'))
            ->assertOk()
            ->assertJsonCount(2, 'visits')
            ->assertJsonPath('visits.0.event', 'node_completed')
            ->assertJsonPath('visits.0.status', 'done')
            ->assertJsonPath('visits.0.handle', 'error')
            ->assertJsonPath('visits.0.durationMs', 120.25)
            ->assertJsonPath('visits.0.iterations', ['loop' => 4])
            ->assertJsonPath('visits.1.event', 'node_entered')
            ->assertJsonPath('visits.1.status', 'running');

        $this->assertStringNotContainsString(self::SECRET, (string) $response->getContent());

        $this->actingAs($this->admin())->getJson($this->visitsUrl($session, $child, 'call'))->assertOk()->assertJsonCount(1, 'visits');
        $this->actingAs($this->admin())->getJson($this->visitsUrl($session, $stranger, 'call'))->assertNotFound();
        $this->actingAs($this->admin())->getJson($this->visitsUrl($session, $session, ''))->assertNotFound();
    }

    public function test_the_graph_needs_the_flow_sessions_permission_and_the_assistants_session(): void
    {
        $definition = $this->publish('Orders', [], []);
        $session    = $this->sessionFor($definition, FlowSessionStatus::Active);
        $denied     = $this->userWith(Permission::ManageFlow);

        $this->actingAs($denied)->get($this->graphUrl($session))->assertForbidden();
        $this->actingAs($denied)->getJson($this->visitsUrl($session, $session, 'x'))->assertForbidden();

        $this->actingAs($this->userWith(Permission::ViewFlowSessions))->get($this->graphUrl($session))->assertOk();

        $sibling = Assistant::factory()->create();
        $foreign = $this->sessionFor($definition, FlowSessionStatus::Active, ['assistant_id' => $sibling->getKey()]);

        $this->actingAs($this->admin())->get($this->graphUrl($foreign))->assertNotFound();
        $this->actingAs($this->admin())->get($this->panelUrl("/assistant/{$this->assistant->getKey()}/flow-sessions/not-a-uuid/graph"))->assertNotFound();
    }

    public function test_the_session_page_links_to_its_graph(): void
    {
        $session = $this->sessionFor($this->publish('Orders', [], []), FlowSessionStatus::Active);

        $this->actingAs($this->admin())
            ->get($this->panelUrl("/assistant/{$this->assistant->getKey()}/flow-sessions/{$session->getKey()}"))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('urls.graph', "/assistant/{$this->assistant->getKey()}/flow-sessions/{$session->getKey()}/graph")
                ->etc());
    }

    private function graphUrl(FlowSession $session): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/flow-sessions/{$session->getKey()}/graph");
    }

    private function visitsUrl(FlowSession $root, FlowSession $session, string $node): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/flow-sessions/{$root->getKey()}/graph/visits?session={$session->getKey()}&node={$node}");
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    private function publish(string $name, array $nodes, array $edges): FlowDefinition
    {
        $flow = FlowDraft::factory()->create(['assistant_id' => $this->assistant->getKey(), 'name' => $name]);

        return FlowDefinition::query()->create([
            'tenant_id' => $flow->tenant_id,
            'flow_id'   => $flow->flow_id,
            'version'   => 1,
            'name'      => $name,
            'nodes'     => $nodes,
            'edges'     => $edges,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function sessionFor(FlowDefinition $definition, FlowSessionStatus $status, array $attributes = []): FlowSession
    {
        return FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $this->assistant->getKey(),
            'contact_id'         => Contact::factory()->forTenant(self::TENANT_ID)->create()->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => $definition->version,
            'current_node_id'    => null,
            'state'              => [],
            'status'             => $status,
            'version'            => 0,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function trace(FlowSession $session, string $nodeId, HistoryEventType $event, array $metadata): void
    {
        $this->clock = $this->clock->copy()->addSecond();

        FlowSessionHistoryEntry::query()->create([
            'tenant_id'  => self::TENANT_ID,
            'session_id' => $session->getKey(),
            'node_id'    => $nodeId,
            'event_type' => $event,
            'metadata'   => $metadata,
            'created_at' => $this->clock,
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
