<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\SolutionAvailabilityInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\StateNamespaceViolationException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\State\StateNamespaceRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;
use Tests\Fixtures\Solution\FeedbackCalls;
use Tests\Fixtures\Solution\Nodes\FeedbackCollectNode;

/**
 * What the engine does with a node of a Solution: it runs while the Solution is available to the tenant, and takes
 * the node's `error` exit with `solution_inactive`, `solution_not_installed` or `solution_version_missing` (never building the handler) when it
 * is not. A Core type nobody registered still fails hard.
 */
final class FlowEngineSolutionNodeTest extends FeatureTestCase
{
    private FeedbackCalls $calls;

    /** @var list<string> */
    private array $active = ['feedback'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->calls = new FeedbackCalls();
        $this->app->instance(FeedbackCalls::class, $this->calls);

        $this->app->make(StateNamespaceRegistry::class)->registerSolutionNamespace('feedback');
        $this->app->make(NodeHandlerRegistry::class)->register(FeedbackCollectNode::class, 'feedback');

        $test = $this;
        $this->app->instance(SolutionAvailabilityInterface::class, new class ($test) implements SolutionAvailabilityInterface {
            public function __construct(private readonly FlowEngineSolutionNodeTest $test)
            {
            }

            public function isAvailable(?string $solutionId): bool
            {
                return null === $solutionId || in_array($solutionId, $this->test->activeSolutions(), true);
            }
        });
    }

    /**
     * @return list<string>
     */
    public function activeSolutions(): array
    {
        return $this->active;
    }

    public function test_a_node_of_an_available_solution_runs_and_writes_its_namespace(): void
    {
        $built   = $this->calls->constructions;
        $session = $this->runFlow('feedback.collect');

        $this->assertSame($built + 1, $this->calls->constructions, 'The handler was built once for the run.');
        $this->assertCount(1, $this->calls->calls);
        $this->assertSame(5, $session->state['feedback']['last_score']);
        $this->assertSame('ok', $this->endNode($session));
    }

    public function test_a_node_of_an_inactive_solution_takes_the_error_exit_without_building_the_handler(): void
    {
        $this->active = [];
        $built        = $this->calls->constructions;

        $session = $this->runFlow('feedback.collect');

        $this->assertSame($built, $this->calls->constructions, 'The handler was not built.');
        $this->assertSame([], $this->calls->calls);
        $this->assertSame('refused', $this->endNode($session));
        $this->assertArrayNotHasKey('feedback', $session->state);
        $this->assertSame('solution_inactive: feedback', $this->errorLoggedFor($session, 'collect'));

        $log = DB::table('flow_logs')->where('session_id', $session->getKey())->where('node_id', 'collect')->sole();
        $this->assertSame('feedback.collect', $log->node_type);
        $this->assertSame('error', $log->source_handle);
    }

    public function test_a_node_of_a_solution_that_is_not_installed_takes_the_error_exit(): void
    {
        $session = $this->runFlow('ghost.collect');

        $this->assertSame('refused', $this->endNode($session));
        $this->assertSame('solution_not_installed: ghost', $this->errorLoggedFor($session, 'collect'));
    }

    public function test_an_unknown_version_of_a_solution_node_is_reported_as_version_missing(): void
    {
        $session = $this->runFlow('feedback.collect', version: 9);

        $this->assertSame('solution_version_missing: feedback', $this->errorLoggedFor($session, 'collect'));
    }

    public function test_without_an_error_edge_the_session_completes_like_any_unconnected_exit(): void
    {
        $this->active = [];

        $session = $this->runFlow('feedback.collect', withErrorEdge: false);

        $this->assertSame(FlowSessionStatus::Completed, $session->status);
        $this->assertSame('solution_inactive: feedback', $this->errorLoggedFor($session, 'collect'));
        $this->assertNull($this->endNode($session));
    }

    public function test_availability_is_asked_on_every_run(): void
    {
        $this->assertSame('ok', $this->endNode($this->runFlow('feedback.collect')));

        $this->active = [];
        $this->assertSame('refused', $this->endNode($this->runFlow('feedback.collect')));

        $this->active = ['feedback'];
        $this->assertSame('ok', $this->endNode($this->runFlow('feedback.collect')));
    }

    public function test_another_solutions_node_may_not_write_the_namespace(): void
    {
        $this->app->make(NodeHandlerRegistry::class)->register(IntruderNode::class, 'intruder');
        $this->active[] = 'intruder';

        $this->expectException(StateNamespaceViolationException::class);

        $this->runFlow('intruder.write');
    }

    public function test_a_branch_and_a_template_read_the_state_namespace_a_solution_node_wrote(): void
    {
        $this->app->make(NodeHandlerRegistry::class)->register(StateTemplateProbeNode::class);

        $session = $this->runCustomFlow(
            [
                ['id' => 'collect', 'type' => 'feedback.collect', 'version' => 1, 'config' => ['score' => 4]],
                ['id' => 'check', 'type' => 'branch', 'version' => 1, 'config' => ['rules' => [
                    ['left' => ['ref' => 'source', 'source' => 'feedback', 'path' => 'last_score'], 'operator' => 'eq', 'value' => 4, 'handle' => 'high'],
                ]]],
                ['id' => 'probe', 'type' => 'state_template_probe', 'version' => 1, 'config' => ['template' => 'Score: {{feedback.last_score}}']],
                ['id' => 'low', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'failed']],
            ],
            [
                ['id' => 'e1', 'from' => 'collect', 'to' => 'check', 'handle' => 'default'],
                ['id' => 'e2', 'from' => 'check', 'to' => 'probe', 'handle' => 'high'],
                ['id' => 'e3', 'from' => 'check', 'to' => 'low', 'handle' => 'default'],
            ],
        );

        $this->assertSame(FlowSessionStatus::Completed, $session->status);
        $this->assertSame('Score: 4', $session->state['flow']['rendered'] ?? null);
        $this->assertNull(DB::table('flow_logs')->where('session_id', $session->getKey())->where('node_id', 'low')->first(), 'The branch took the high exit.');
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    private function runCustomFlow(array $nodes, array $edges): FlowSession
    {
        $tenantId  = (string) Str::uuid();
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create();
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Solution state',
            'nodes'     => $nodes,
            'edges'     => $edges,
            'is_active' => true,
        ]);

        return $this->app->make(FlowEngineInterface::class)->start($definition, $contact);
    }

    private function runFlow(string $type, int $version = 1, bool $withErrorEdge = true): FlowSession
    {
        $tenantId  = (string) Str::uuid();
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create();
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $edges = [['id' => 'e1', 'from' => 'collect', 'to' => 'ok', 'handle' => 'default']];

        if ($withErrorEdge) {
            $edges[] = ['id' => 'e2', 'from' => 'collect', 'to' => 'refused', 'handle' => 'error'];
        }

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Solution node',
            'nodes'     => [
                ['id' => 'collect', 'type' => $type, 'version' => $version, 'config' => []],
                ['id' => 'ok', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
                ...($withErrorEdge ? [['id' => 'refused', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'failed']]] : []),
            ],
            'edges'     => $edges,
            'is_active' => true,
        ]);

        return $this->app->make(FlowEngineInterface::class)->start($definition, $contact);
    }

    /**
     * The id of the end node the session reached, or null when it ended elsewhere.
     */
    private function endNode(FlowSession $session): ?string
    {
        $log = DB::table('flow_logs')->where('session_id', $session->getKey())->whereIn('node_id', ['ok', 'refused'])->first();

        return null === $log ? null : (string) $log->node_id;
    }

    private function errorLoggedFor(FlowSession $session, string $nodeId): ?string
    {
        $log = DB::table('flow_logs')->where('session_id', $session->getKey())->where('node_id', $nodeId)->sole();

        return null === $log->error ? null : (string) (json_decode((string) $log->error, true)['message'] ?? $log->error);
    }
}

final class IntruderNode extends \Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler
{
    final public const string TYPE = 'intruder.write';

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'Logic';
    }

    public function execute(array $nodeConfig, array $state, \Fapost\Foundation\DTO\NodeExecutionContext $context): \Fapost\Foundation\DTO\NodeExecutionResult
    {
        return \Fapost\Foundation\DTO\NodeExecutionResult::executed('default', ['feedback.last_score' => 1]);
    }
}

final class StateTemplateProbeNode extends \Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler
{
    final public const string TYPE = 'state_template_probe';

    public function __construct(private readonly \App\Domains\Flow\Handlers\Support\TemplateRenderer $templates)
    {
    }

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'Logic';
    }

    public function execute(array $nodeConfig, array $state, \Fapost\Foundation\DTO\NodeExecutionContext $context): \Fapost\Foundation\DTO\NodeExecutionResult
    {
        $rendered = $this->templates->render($nodeConfig['config']['template'] ?? '', $context, $state);

        return \Fapost\Foundation\DTO\NodeExecutionResult::executed('default', ['flow.rendered' => $rendered]);
    }
}
