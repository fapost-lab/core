<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\FlowExecutionLimitExceededException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * Integration tests for loop / loop_end nodes executing through the full FlowEngine.
 */
final class LoopEngineTest extends FeatureTestCase
{
    public function test_counted_loop_executes_body_n_times_and_completes(): void
    {
        $tenantId  = (string) Str::uuid();
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        // Flow: loop(3) → body_node → loop_end → (back to loop) → after_loop → end
        $definition = FlowDefinition::query()->create([
            'tenant_id'       => $tenantId,
            'flow_id'         => (string) Str::uuid(),
            'version'         => 1,
            'name'            => 'Counted Loop',
            'is_active'       => true,
            'logging_enabled' => false,
            'nodes'           => [
                [
                    'id'      => 'loop',
                    'type'    => 'loop',
                    'version' => 1,
                    'config'  => [
                        'mode'          => 'counted',
                        'iterator_name' => 'iterator',
                        'count_source'  => ['type' => 'literal', 'value' => 3],
                    ],
                ],
                [
                    'id'      => 'body',
                    'type'    => 'loop_end',
                    'version' => 1,
                    'config'  => [
                        'loop_node_id'  => 'loop',
                        'iterator_name' => 'iterator',
                    ],
                ],
                [
                    'id'      => 'done',
                    'type'    => 'end',
                    'version' => 1,
                    'config'  => ['status' => 'success'],
                ],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'loop', 'to' => 'body', 'handle' => 'loop'],
                ['id' => 'e2', 'from' => 'loop', 'to' => 'done', 'handle' => 'default'],
            ],
        ]);

        $engine  = $this->app->make(FlowEngineInterface::class);
        $session = $engine->start($definition, $contact);

        $this->assertSame(FlowSessionStatus::Ended, $session->status);

        // Iterator keys should be absent or explicitly null after cleanup.
        $state = $session->state;
        $this->assertNull($state['flow']['iterator'] ?? null);
        $this->assertNull($state['flow']['iterator_total'] ?? null);
    }

    public function test_counted_loop_body_executes_exactly_n_times(): void
    {
        $tenantId  = (string) Str::uuid();
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        // Compact loop: loop(2) → loop_end → (back) → end
        $definition = FlowDefinition::query()->create([
            'tenant_id'       => $tenantId,
            'flow_id'         => (string) Str::uuid(),
            'version'         => 1,
            'name'            => 'Compact Counted Loop',
            'is_active'       => true,
            'logging_enabled' => false,
            'nodes'           => [
                [
                    'id'      => 'loop',
                    'type'    => 'loop',
                    'version' => 1,
                    'config'  => [
                        'mode'          => 'counted',
                        'iterator_name' => 'i',
                        'count_source'  => ['type' => 'literal', 'value' => 2],
                    ],
                ],
                [
                    'id'      => 'le',
                    'type'    => 'loop_end',
                    'version' => 1,
                    'config'  => [
                        'loop_node_id'  => 'loop',
                        'iterator_name' => 'i',
                    ],
                ],
                [
                    'id'      => 'end',
                    'type'    => 'end',
                    'version' => 1,
                    'config'  => ['status' => 'success'],
                ],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'loop', 'to' => 'le', 'handle' => 'loop'],
                ['id' => 'e2', 'from' => 'loop', 'to' => 'end', 'handle' => 'default'],
            ],
        ]);

        $engine  = $this->app->make(FlowEngineInterface::class);
        $session = $engine->start($definition, $contact);

        $this->assertSame(FlowSessionStatus::Ended, $session->status);
    }

    public function test_while_loop_exits_immediately_when_condition_initially_false(): void
    {
        $tenantId  = (string) Str::uuid();
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        // While loop condition checks flow.flag == 'yes' but state has 'no'.
        $definition = FlowDefinition::query()->create([
            'tenant_id'       => $tenantId,
            'flow_id'         => (string) Str::uuid(),
            'version'         => 1,
            'name'            => 'While Loop Immediate Exit',
            'is_active'       => true,
            'logging_enabled' => false,
            'nodes'           => [
                [
                    'id'      => 'loop',
                    'type'    => 'loop',
                    'version' => 1,
                    'config'  => [
                        'mode'          => 'while',
                        'iterator_name' => 'iterator',
                        'condition'     => [
                            'left'     => 'flow.flag',
                            'operator' => 'eq',
                            'value'    => 'yes',
                        ],
                    ],
                ],
                [
                    'id'      => 'le',
                    'type'    => 'loop_end',
                    'version' => 1,
                    'config'  => [
                        'loop_node_id'  => 'loop',
                        'iterator_name' => 'iterator',
                    ],
                ],
                [
                    'id'      => 'end',
                    'type'    => 'end',
                    'version' => 1,
                    'config'  => ['status' => 'success'],
                ],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'loop', 'to' => 'le', 'handle' => 'loop'],
                ['id' => 'e2', 'from' => 'loop', 'to' => 'end', 'handle' => 'default'],
            ],
        ]);

        // flow.flag is not set in state so eq 'yes' is false on first check → loop exits immediately.
        $engine  = $this->app->make(FlowEngineInterface::class);
        $session = $engine->start($definition, $contact);

        $this->assertSame(FlowSessionStatus::Ended, $session->status);
    }

    public function test_loop_iterator_null_after_exit(): void
    {
        $tenantId  = (string) Str::uuid();
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $definition = FlowDefinition::query()->create([
            'tenant_id'       => $tenantId,
            'flow_id'         => (string) Str::uuid(),
            'version'         => 1,
            'name'            => 'Iterator Cleanup',
            'is_active'       => true,
            'logging_enabled' => false,
            'nodes'           => [
                [
                    'id'      => 'loop',
                    'type'    => 'loop',
                    'version' => 1,
                    'config'  => [
                        'mode'         => 'counted',
                        'count_source' => ['type' => 'literal', 'value' => 1],
                    ],
                ],
                [
                    'id'      => 'le',
                    'type'    => 'loop_end',
                    'version' => 1,
                    'config'  => [
                        'loop_node_id'  => 'loop',
                        'iterator_name' => 'iterator',
                    ],
                ],
                [
                    'id'      => 'end',
                    'type'    => 'end',
                    'version' => 1,
                    'config'  => ['status' => 'success'],
                ],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'loop', 'to' => 'le', 'handle' => 'loop'],
                ['id' => 'e2', 'from' => 'loop', 'to' => 'end', 'handle' => 'default'],
            ],
        ]);

        $engine  = $this->app->make(FlowEngineInterface::class);
        $session = $engine->start($definition, $contact);

        $this->assertSame(FlowSessionStatus::Ended, $session->status);

        // After loop exit the iterator key should be absent or null.
        $state = $session->state;
        $this->assertNull($state['flow']['iterator'] ?? null);
    }

    public function test_a_call_node_in_a_loop_sends_a_different_idempotency_key_on_each_pass(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);

        $tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: $tenantId, schemaName: 'main'));

        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $definition = FlowDefinition::query()->create([
            'tenant_id'       => $tenantId,
            'flow_id'         => (string) Str::uuid(),
            'version'         => 1,
            'name'            => 'Call In Loop',
            'is_active'       => true,
            'logging_enabled' => false,
            'nodes'           => [
                [
                    'id'      => 'loop',
                    'type'    => 'loop',
                    'version' => 1,
                    'config'  => [
                        'mode'          => 'counted',
                        'iterator_name' => 'iterator',
                        'count_source'  => ['type' => 'literal', 'value' => 3],
                    ],
                ],
                [
                    'id'      => 'call',
                    'type'    => 'call',
                    'version' => 1,
                    'config'  => ['transport' => 'http', 'target' => 'POST https://api.example.com/hook'],
                ],
                [
                    'id'      => 'body',
                    'type'    => 'loop_end',
                    'version' => 1,
                    'config'  => ['loop_node_id' => 'loop', 'iterator_name' => 'iterator'],
                ],
                ['id' => 'done', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'loop', 'to' => 'call', 'handle' => 'loop'],
                ['id' => 'e2', 'from' => 'call', 'to' => 'body', 'handle' => 'success'],
                ['id' => 'e3', 'from' => 'call', 'to' => 'body', 'handle' => 'error'],
                ['id' => 'e4', 'from' => 'loop', 'to' => 'done', 'handle' => 'default'],
            ],
        ]);

        $session = $this->app->make(FlowEngineInterface::class)->start($definition, $contact);

        $this->assertSame(FlowSessionStatus::Ended, $session->status);

        $keys = Http::recorded()->map(static fn (array $pair): string => $pair[0]->header('Idempotency-Key')[0])->all();

        $this->assertCount(3, $keys);
        $this->assertCount(3, array_unique($keys));
    }

    public function test_a_batch_loop_longer_than_the_step_budget_runs_to_its_end(): void
    {
        // 200 iterations of loop + body + loop_end are 600 steps; only entering the loop costs the shared budget.
        Config::set('flow.execution.max_iterations', 100);
        Config::set('flow.execution.max_loop_iterations', 1000);

        $session = $this->runLoop(count: 200);

        $this->assertSame(FlowSessionStatus::Ended, $session->status);
        $this->assertNull($session->state['flow']['i'] ?? null);
    }

    public function test_a_loop_may_run_exactly_its_iteration_limit(): void
    {
        Config::set('flow.execution.max_loop_iterations', 5);

        $this->assertSame(FlowSessionStatus::Ended, $this->runLoop(count: 5)->status);
    }

    public function test_a_loop_past_its_iteration_limit_fails_naming_the_loop_node(): void
    {
        Config::set('flow.execution.max_loop_iterations', 5);

        try {
            $this->runLoop(count: 6);
            $this->fail('Expected FlowExecutionLimitExceededException');
        } catch (FlowExecutionLimitExceededException $exception) {
            $this->assertStringContainsString("Loop node 'loop' exceeded 5 iterations", $exception->getMessage());
        }

        $this->assertSame(FlowSessionStatus::Failed, FlowSession::query()->sole()->status);
    }

    public function test_a_loop_body_that_never_reaches_its_loop_end_fails_at_the_step_budget(): void
    {
        // The body leads straight back to the loop node: the iterator never moves, so only the per-iteration
        // allowance (max_iterations) stops it.
        Config::set('flow.execution.max_iterations', 10);

        try {
            $this->runLoop(count: 3, bodyBackToLoop: true);
            $this->fail('Expected FlowExecutionLimitExceededException');
        } catch (FlowExecutionLimitExceededException $exception) {
            $this->assertStringContainsString("One iteration of loop node 'loop' exceeded 10 steps", $exception->getMessage());
        }
    }

    public function test_steps_outside_a_loop_still_count_against_the_shared_budget(): void
    {
        // Every pass through the loop node from outside the loop is a shared step: a cycle around the loop fails.
        Config::set('flow.execution.max_iterations', 10);

        try {
            $this->runLoop(count: 1, exitBackToLoop: true);
            $this->fail('Expected FlowExecutionLimitExceededException');
        } catch (FlowExecutionLimitExceededException $exception) {
            $this->assertStringContainsString('exceeded 10 steps outside loops', $exception->getMessage());
        }
    }

    public function test_a_conversation_loop_with_a_subflow_body_fails_at_the_time_bound_of_the_outermost_call(): void
    {
        // Each child run takes 10 s; the parent resumes inside the child's last step, in a nested engine call, and
        // must still stop on the clock of the call that started it.
        Config::set('flow.execution.max_loop_iterations', 1000);
        CarbonImmutable::setTestNow('2026-11-19 10:00:00');

        try {
            $this->runSubflowLoop(count: 300, childSeconds: 10);
            $this->fail('Expected FlowExecutionLimitExceededException');
        } catch (FlowExecutionLimitExceededException $exception) {
            $this->assertStringContainsString("Loop node 'loop' was still running after 30 seconds", $exception->getMessage());
        } finally {
            CarbonImmutable::setTestNow();
        }

        $parent = FlowSession::query()->whereNull('parent_session_id')->sole();

        $this->assertSame(FlowSessionStatus::Failed, $parent->status);
        $this->assertLessThanOrEqual(4, (int) ($parent->state['flow']['i'] ?? 0));
    }

    public function test_a_conversation_loop_with_a_subflow_body_holds_its_iteration_limit(): void
    {
        Config::set('flow.execution.max_loop_iterations', 3);

        try {
            $this->runSubflowLoop(count: 5, childSeconds: 0);
            $this->fail('Expected FlowExecutionLimitExceededException');
        } catch (FlowExecutionLimitExceededException $exception) {
            $this->assertStringContainsString("Loop node 'loop' exceeded 3 iterations", $exception->getMessage());
        }

        $this->assertSame(FlowSessionStatus::Failed, FlowSession::query()->whereNull('parent_session_id')->sole()->status);
    }

    /**
     * Runs start -> loop(count) -> body (assign) -> loop_end, exiting through `after` to an end node. The start node
     * keeps the loop from being the entry even when an edge leads back to it.
     *
     * @param  bool  $bodyBackToLoop  the body links back to the loop node instead of to its loop_end
     * @param  bool  $exitBackToLoop  the loop's exit leads through a node back to the loop node instead of to the end
     */
    private function runLoop(int $count, bool $bodyBackToLoop = false, bool $exitBackToLoop = false): FlowSession
    {
        $tenantId  = (string) Str::uuid();
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $definition = FlowDefinition::query()->create([
            'tenant_id'       => $tenantId,
            'flow_id'         => (string) Str::uuid(),
            'version'         => 1,
            'name'            => 'Budget Loop',
            'is_active'       => true,
            'logging_enabled' => false,
            // A node nothing links to would be a second entry, so a variant drops the node it bypasses.
            'nodes' => array_values(array_filter([
                ['id' => 'start', 'type' => 'assign', 'version' => 1, 'config' => ['operations' => []]],
                ['id' => 'loop', 'type' => 'loop', 'version' => 1, 'config' => [
                    'mode'          => 'counted',
                    'iterator_name' => 'i',
                    'count_source'  => ['type' => 'literal', 'value' => $count],
                ]],
                ['id' => 'body', 'type' => 'assign', 'version' => 1, 'config' => ['operations' => []]],
                ['id' => 'le', 'type' => 'loop_end', 'version' => 1, 'config' => ['loop_node_id' => 'loop', 'iterator_name' => 'i']],
                ['id' => 'after', 'type' => 'assign', 'version' => 1, 'config' => ['operations' => []]],
                ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ], static fn (array $node): bool => !($bodyBackToLoop && 'le' === $node['id']) && !($exitBackToLoop && 'end' === $node['id']))),
            'edges' => [
                ['id' => 'e0', 'from' => 'start', 'to' => 'loop', 'handle' => 'default'],
                ['id' => 'e1', 'from' => 'loop', 'to' => 'body', 'handle' => 'loop'],
                ['id' => 'e2', 'from' => 'body', 'to' => $bodyBackToLoop ? 'loop' : 'le', 'handle' => 'default'],
                ['id' => 'e3', 'from' => 'loop', 'to' => 'after', 'handle' => 'default'],
                ['id' => 'e4', 'from' => 'after', 'to' => $exitBackToLoop ? 'loop' : 'end', 'handle' => 'default'],
            ],
        ]);

        return $this->app->make(FlowEngineInterface::class)->start($definition, $contact);
    }

    /**
     * Runs a conversation start -> loop(count) -> subflow -> loop_end, exiting to an end node; the child is one
     * {@see ClockAdvancingTestHandler} node taking {@code $childSeconds} and an end node.
     */
    private function runSubflowLoop(int $count, int $childSeconds): FlowSession
    {
        $tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: $tenantId, schemaName: 'main'));
        $this->app->make(NodeHandlerRegistry::class)->register(ClockAdvancingTestHandler::class);

        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $childFlowId = (string) Str::uuid();

        FlowDefinition::query()->create([
            'tenant_id'       => $tenantId,
            'flow_id'         => $childFlowId,
            'version'         => 1,
            'name'            => 'Slow Child',
            'is_active'       => true,
            'logging_enabled' => false,
            'nodes'           => [
                ['id' => 'c-slow', 'type' => 'clock_advancing_test', 'version' => 1, 'config' => ['seconds' => $childSeconds]],
                ['id' => 'c-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges' => [
                ['id' => 'c-e1', 'from' => 'c-slow', 'to' => 'c-end', 'handle' => 'default'],
            ],
        ]);

        $parent = FlowDefinition::query()->create([
            'tenant_id'       => $tenantId,
            'flow_id'         => (string) Str::uuid(),
            'version'         => 1,
            'name'            => 'Subflow Loop',
            'is_active'       => true,
            'logging_enabled' => false,
            'nodes'           => [
                ['id' => 'start', 'type' => 'assign', 'version' => 1, 'config' => ['operations' => []]],
                ['id' => 'loop', 'type' => 'loop', 'version' => 1, 'config' => [
                    'mode'          => 'counted',
                    'iterator_name' => 'i',
                    'count_source'  => ['type' => 'literal', 'value' => $count],
                ]],
                ['id' => 'sub', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $childFlowId, 'timeout' => 'PT1H']],
                ['id' => 'le', 'type' => 'loop_end', 'version' => 1, 'config' => ['loop_node_id' => 'loop', 'iterator_name' => 'i']],
                ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges' => [
                ['id' => 'e0', 'from' => 'start', 'to' => 'loop', 'handle' => 'default'],
                ['id' => 'e1', 'from' => 'loop', 'to' => 'sub', 'handle' => 'loop'],
                ['id' => 'e2', 'from' => 'sub', 'to' => 'le', 'handle' => 'success'],
                ['id' => 'e3', 'from' => 'loop', 'to' => 'end', 'handle' => 'default'],
            ],
        ]);

        return $this->app->make(FlowEngineInterface::class)->start($parent, $contact);
    }
}

/**
 * Moves the test clock forward by {@code config.seconds}, standing in for a slow node (a call to a slow endpoint).
 */
final class ClockAdvancingTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'clock_advancing_test';
    }

    public function version(): int
    {
        return 1;
    }

    public function supportedVersions(): array
    {
        return [1];
    }

    public function label(): string
    {
        return 'Clock Advancing Test';
    }

    public function category(): string
    {
        return 'Logic';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $seconds = $nodeConfig['config']['seconds'] ?? 0;

        if (is_int($seconds) && $seconds > 0) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds($seconds));
        }

        return new NodeExecutionResult(status: NodeExecutionStatus::Executed, sourceHandle: 'default');
    }
}
