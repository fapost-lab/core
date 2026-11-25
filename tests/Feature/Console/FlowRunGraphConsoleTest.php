<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowRunKind;
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
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;

/**
 * The run graph of a flow session: the executed definition with what each node did, loop passes, subflow children as
 * frames, a node's visits — statuses only — and, for a holder of `ViewRunPayloads`, one visit's input, output and
 * error message at a time.
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

        // Neither a node's payloads, its error message nor its config reach the page — not even for a holder of
        // ViewRunPayloads (an admin), nor for a user who may only view sessions.
        $this->assertStringNotContainsString(self::SECRET, (string) $response->getContent());
        $this->assertStringNotContainsString('secret text', (string) $response->getContent());

        $statusesOnly = $this->actingAs($this->userWith(Permission::ViewFlowSessions))->get($this->graphUrl($session))->assertOk();

        $this->assertStringNotContainsString(self::SECRET, (string) $statusesOnly->getContent());
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

    public function test_a_subflow_node_keeps_waiting_while_its_child_sleeps_in_a_delay(): void
    {
        $parentDefinition = $this->publish('Parent', [
            ['id' => 'call-child', 'type' => 'subflow', 'version' => 1, 'config' => []],
            ['id' => 'done', 'type' => 'end', 'version' => 1, 'config' => []],
        ], [['from' => 'call-child', 'to' => 'done', 'handle' => 'success']]);
        $childDefinition = $this->publish('Child', [['id' => 'sleep', 'type' => 'delay', 'version' => 1, 'config' => []]], []);
        $parent          = $this->sessionFor($parentDefinition, FlowSessionStatus::PausedSubflow);
        $this->sessionFor($childDefinition, FlowSessionStatus::Paused, [
            'parent_session_id' => $parent->getKey(), 'parent_resume_node_id' => 'call-child',
        ]);
        $this->trace($parent, 'call-child', HistoryEventType::NodeEntered, ['type' => 'subflow']);
        $this->trace($parent, 'call-child', HistoryEventType::NodeCompleted, ['type' => 'subflow', 'status' => 'waiting']);

        $this->actingAs($this->admin())
            ->get($this->graphUrl($parent))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('graph.flow.nodes.0.run.status', 'waiting')
                ->where('graph.flow.nodes.0.run.handles', [])
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

        $statusesOnly = $this->actingAs($this->userWith(Permission::ViewFlowSessions))
            ->getJson($this->visitsUrl($session, $session, 'call'))
            ->assertOk()
            ->assertJsonCount(2, 'visits');

        $this->assertStringNotContainsString(self::SECRET, (string) $statusesOnly->getContent());

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

    public function test_the_page_offers_visit_data_only_to_a_holder_of_view_run_payloads(): void
    {
        $session = $this->sessionFor($this->publish('Orders', [], []), FlowSessionStatus::Completed, ['run_kind' => FlowRunKind::Automation]);
        $base    = "/assistant/{$this->assistant->getKey()}/flow-sessions/{$session->getKey()}";

        $this->actingAs($this->userWith(Permission::ViewFlowSessions, Permission::ViewRunPayloads))
            ->get($this->graphUrl($session))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canViewPayloads', true)
                ->where('urls.payload', "{$base}/graph/visits/__ENTRY__")
                ->where('graph.flow.runKind', 'automation')
                ->etc());

        $this->actingAs($this->userWith(Permission::ViewFlowSessions))
            ->get($this->graphUrl($session))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canViewPayloads', false)
                ->where('urls.payload', null)
                ->etc());
    }

    public function test_a_visit_says_whether_it_carries_data_only_to_a_holder_of_view_run_payloads(): void
    {
        $definition = $this->publish('Calls', [['id' => 'call', 'type' => 'call', 'version' => 1, 'config' => []]], []);
        $session    = $this->sessionFor($definition, FlowSessionStatus::Failed);

        $this->trace($session, 'call', HistoryEventType::NodeEntered, ['type' => 'call']);
        $this->trace($session, 'call', HistoryEventType::NodeCompleted, ['type' => 'call', 'status' => 'executed', 'output' => ['body' => self::SECRET]]);
        $this->trace($session, 'call', HistoryEventType::NodeFailed, ['type' => 'call', 'error' => ['class' => 'RuntimeException', 'message' => self::SECRET]]);
        $this->trace($session, 'call', HistoryEventType::NodeCompleted, ['type' => 'call', 'status' => 'executed', 'error' => ['class' => 'RuntimeException']]);

        $response = $this->actingAs($this->userWith(Permission::ViewFlowSessions, Permission::ViewRunPayloads))
            ->getJson($this->visitsUrl($session, $session, 'call'))
            ->assertOk()
            ->assertJsonCount(4, 'visits')
            ->assertJsonPath('visits.0.hasData', false)
            ->assertJsonPath('visits.1.hasData', true)
            ->assertJsonPath('visits.2.hasData', true)
            ->assertJsonPath('visits.3.hasData', false);

        $this->assertStringNotContainsString(self::SECRET, (string) $response->getContent());

        $visits = $this->actingAs($this->userWith(Permission::ViewFlowSessions))
            ->getJson($this->visitsUrl($session, $session, 'call'))
            ->assertOk()
            ->json('visits');

        $this->assertIsArray($visits);

        foreach ($visits as $visit) {
            $this->assertIsArray($visit);
            $this->assertArrayNotHasKey('hasData', $visit);
        }
    }

    public function test_a_holder_of_view_run_payloads_reads_one_visits_data_of_the_run_and_its_subflow_children(): void
    {
        $definition = $this->publish('Calls', [['id' => 'call', 'type' => 'call', 'version' => 1, 'config' => []]], []);
        $session    = $this->sessionFor($definition, FlowSessionStatus::Failed, ['run_kind' => FlowRunKind::Automation]);
        $child      = $this->sessionFor($definition, FlowSessionStatus::Failed, ['parent_session_id' => $session->getKey(), 'parent_resume_node_id' => 'call']);
        $holder     = $this->userWith(Permission::ViewFlowSessions, Permission::ViewRunPayloads);

        $completed = $this->trace($session, 'call', HistoryEventType::NodeCompleted, [
            'type'   => 'call', 'status' => 'executed', 'handle' => 'default',
            'input'  => '[truncated, 2048 bytes]',
            'output' => ['body' => ['token' => '***', 'note' => '[truncated, 300 bytes]']],
        ]);
        $failed = $this->trace($child, 'call', HistoryEventType::NodeFailed, [
            'type' => 'call', 'error' => ['class' => 'RuntimeException', 'message' => 'Timeout calling <script>x</script>'],
        ]);

        $response = $this->actingAs($holder)
            ->getJson($this->payloadUrl($session, $session, $completed))
            ->assertOk()
            ->assertExactJson([
                'input'        => '[truncated, 2048 bytes]',
                'output'       => ['body' => ['token' => '***', 'note' => '[truncated, 300 bytes]']],
                'errorMessage' => null,
            ]);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->actingAs($holder)
            ->getJson($this->payloadUrl($session, $child, $failed))
            ->assertOk()
            ->assertExactJson(['input' => null, 'output' => null, 'errorMessage' => 'Timeout calling <script>x</script>']);
    }

    public function test_visit_data_needs_view_run_payloads(): void
    {
        $session = $this->sessionFor($this->publish('Calls', [], []), FlowSessionStatus::Failed);
        $entry   = $this->trace($session, 'call', HistoryEventType::NodeCompleted, ['type' => 'call', 'output' => ['body' => self::SECRET]]);

        $this->actingAs($this->userWith(Permission::ViewFlowSessions))->getJson($this->payloadUrl($session, $session, $entry))->assertForbidden();
        $this->actingAs($this->userWith(Permission::ViewRunPayloads))->getJson($this->payloadUrl($session, $session, $entry))->assertForbidden();
    }

    public function test_visit_data_is_throttled_on_a_counter_of_its_own(): void
    {
        $session = $this->sessionFor($this->publish('Calls', [], []), FlowSessionStatus::Failed);
        $entry   = $this->trace($session, 'call', HistoryEventType::NodeCompleted, ['type' => 'call', 'output' => ['body' => 'ok']]);
        $holder  = $this->userWith(Permission::ViewFlowSessions, Permission::ViewRunPayloads);

        // Use up the per-user counter the unprefixed throttles share (the visits list's 120 a minute).
        for ($attempt = 0; $attempt < 120; ++$attempt) {
            RateLimiter::hit(sha1((string) $holder->getKey()), 60);
        }

        $this->actingAs($holder)->getJson($this->visitsUrl($session, $session, 'call'))->assertTooManyRequests();
        $this->actingAs($holder)->getJson($this->payloadUrl($session, $session, $entry))->assertOk();
    }

    public function test_only_a_trace_row_of_a_session_of_the_run_is_found(): void
    {
        $definition = $this->publish('Calls', [], []);
        $session    = $this->sessionFor($definition, FlowSessionStatus::Failed);
        $stranger   = $this->sessionFor($definition, FlowSessionStatus::Failed);
        $sibling    = Assistant::factory()->create();
        $foreign    = $this->sessionFor($definition, FlowSessionStatus::Failed, ['assistant_id' => $sibling->getKey()]);
        $holder     = $this->admin();

        $strangers   = $this->trace($stranger, 'call', HistoryEventType::NodeCompleted, ['output' => ['body' => self::SECRET]]);
        $foreigns    = $this->trace($foreign, 'call', HistoryEventType::NodeCompleted, ['output' => ['body' => self::SECRET]]);
        $state       = $this->trace($session, 'call', HistoryEventType::StateChange, ['input' => self::SECRET]);
        $otherTenant = $this->trace($session, 'call', HistoryEventType::NodeCompleted, ['output' => ['body' => self::SECRET]], '00000000-0000-0000-0000-000000000002');

        // Another session's row, asked for through the run's own session or through that session.
        $this->actingAs($holder)->getJson($this->payloadUrl($session, $session, $strangers))->assertNotFound();
        $this->actingAs($holder)->getJson($this->payloadUrl($session, $stranger, $strangers))->assertNotFound();
        // Another assistant's run.
        $this->actingAs($holder)->getJson($this->payloadUrl($foreign, $foreign, $foreigns))->assertNotFound();
        // A history row that is not the trace, a row of another tenant, an unknown and a malformed id.
        $this->actingAs($holder)->getJson($this->payloadUrl($session, $session, $state))->assertNotFound();
        $this->actingAs($holder)->getJson($this->payloadUrl($session, $session, $otherTenant))->assertNotFound();
        $this->actingAs($holder)->getJson($this->payloadUrl($session, $session, '019a0000-0000-7000-8000-000000000000'))->assertNotFound();
        $this->actingAs($holder)->getJson($this->payloadUrl($session, $session, 'not-a-uuid'))->assertNotFound();
    }

    private function graphUrl(FlowSession $session): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/flow-sessions/{$session->getKey()}/graph");
    }

    private function visitsUrl(FlowSession $root, FlowSession $session, string $node): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/flow-sessions/{$root->getKey()}/graph/visits?session={$session->getKey()}&node={$node}");
    }

    private function payloadUrl(FlowSession $root, FlowSession $session, string $entry): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/flow-sessions/{$root->getKey()}/graph/visits/{$entry}?session={$session->getKey()}");
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
    private function trace(FlowSession $session, string $nodeId, HistoryEventType $event, array $metadata, string $tenantId = self::TENANT_ID): string
    {
        $this->clock = $this->clock->copy()->addSecond();

        return (string) FlowSessionHistoryEntry::query()->create([
            'tenant_id'  => $tenantId,
            'session_id' => $session->getKey(),
            'node_id'    => $nodeId,
            'event_type' => $event,
            'metadata'   => $metadata,
            'created_at' => $this->clock,
        ])->getKey();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    private function userWith(Permission ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([Permission::ManageAssistants->value, ...array_map(static fn (Permission $permission): string => $permission->value, $permissions)]);
        $user->assistants()->attach($this->assistant);

        return $user;
    }
}
