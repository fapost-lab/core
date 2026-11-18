<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Subflow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Services\ValidateFlowService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * A subflow node's `input_mapping` seeds the child's session variables from the parent's state when the child
 * starts, and its `output_mapping` copies child variables back into the parent only when the child hands back on
 * `success`. Real engine, starter and resumer.
 */
final class SubflowIoMappingTest extends FeatureTestCase
{
    private string $tenantId;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(
            new RuntimeTenant(id: $this->tenantId, schemaName: 'main'),
        );

        $this->app->make(NodeHandlerRegistry::class)->register(SetVariablesTestHandler::class);

        $assistant = Assistant::factory()->create([
            'tenant_id'        => $this->tenantId,
            'default_language' => 'en',
        ]);
        $this->contact = Contact::factory()->forTenant($this->tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);
    }

    public function test_the_child_starts_with_the_mapped_values_only(): void
    {
        $childFlowId = $this->childFlow(endStatus: 'success');

        $parent = $this->runParent(
            set: ['user_id' => 42, 'items' => ['a', 'b'], 'name' => 'Ann', 'secret' => 'hidden'],
            subflowConfig: [
                'input_mapping' => [
                    'user'     => '{{flow.user_id}}',
                    'items'    => '{{ flow.items }}',
                    'greeting' => 'Hello, {{flow.name}}!',
                    'source'   => 'parent',
                ],
            ],
            childFlowId: $childFlowId,
        );

        $childState = $this->childOf($parent)->state;

        $this->assertSame(42, $childState['flow']['user'] ?? null, 'a single placeholder keeps the value type');
        $this->assertSame(['a', 'b'], $childState['flow']['items'] ?? null);
        $this->assertSame('Hello, Ann!', $childState['flow']['greeting'] ?? null);
        $this->assertSame('parent', $childState['flow']['source'] ?? null);
        $this->assertArrayNotHasKey('secret', $childState['flow'], 'an unmapped parent variable stays in the parent');
        $this->assertArrayNotHasKey('user_id', $childState['flow']);
    }

    public function test_mapped_child_variables_reach_the_parent_on_success(): void
    {
        $childFlowId = $this->childFlow(endStatus: 'success', set: ['price' => 99.5, 'discount' => ['code' => 'X']]);

        $parent = $this->runParent(
            set: ['keep' => 'me', 'total' => 'stale'],
            subflowConfig: [
                'output_mapping' => [
                    'total'    => 'price',
                    'discount' => 'discount',
                    'missing'  => 'never_set',
                ],
            ],
            childFlowId: $childFlowId,
        );

        $this->assertSame(FlowSessionStatus::Ended, $parent->status);
        $this->assertSame(EndStatus::Success->value, $parent->end_status);
        $this->assertSame(99.5, $parent->state['flow']['total'] ?? null);
        $this->assertSame(['code' => 'X'], $parent->state['flow']['discount'] ?? null);
        $this->assertArrayHasKey('missing', $parent->state['flow']);
        $this->assertNull($parent->state['flow']['missing']);
        $this->assertSame('me', $parent->state['flow']['keep'] ?? null);
        $this->assertSame('after-subflow', $parent->state['flow']['step'] ?? null, 'the parent ran on after the hand-back');
    }

    public function test_a_child_that_runs_off_its_graph_hands_back_its_outputs(): void
    {
        $childFlowId = (string) Str::uuid();
        $this->createDefinition($childFlowId, [
            ['id' => 'c-set', 'type' => 'set_variables_test', 'version' => 1, 'config' => ['set' => ['price' => 7]]],
        ], []);

        $parent = $this->runParent(
            set: [],
            subflowConfig: ['output_mapping' => ['total' => 'price']],
            childFlowId: $childFlowId,
        );

        $this->assertSame(7, $parent->state['flow']['total'] ?? null);
    }

    public function test_nothing_is_handed_back_when_the_child_fails_or_is_cancelled(): void
    {
        foreach (['failed', 'cancelled'] as $endStatus) {
            $childFlowId = $this->childFlow(endStatus: $endStatus, set: ['price' => 1]);

            $parent = $this->runParent(
                set: ['total' => 'before'],
                subflowConfig: ['output_mapping' => ['total' => 'price']],
                childFlowId: $childFlowId,
                handle: $endStatus,
            );

            $this->assertSame('before', $parent->state['flow']['total'] ?? null, "no outputs on {$endStatus}");
            $this->assertSame('after-subflow', $parent->state['flow']['step'] ?? null, "the parent took the {$endStatus} handle");
        }
    }

    public function test_a_node_without_mappings_keeps_parent_and_child_apart(): void
    {
        $childFlowId = $this->childFlow(endStatus: 'success', set: ['price' => 1]);

        $parent = $this->runParent(set: ['total' => 'before'], subflowConfig: [], childFlowId: $childFlowId);

        $this->assertSame('before', $parent->state['flow']['total'] ?? null);
        $this->assertArrayNotHasKey('price', $parent->state['flow']);
        $this->assertArrayNotHasKey('total', $this->childOf($parent)->state['flow'] ?? []);
    }

    public function test_outputs_travel_up_a_nested_chain(): void
    {
        $leafFlowId = $this->childFlow(endStatus: 'success', set: ['answer' => 'deep']);

        $middleFlowId = (string) Str::uuid();
        $this->createDefinition($middleFlowId, [
            ['id' => 'm-sub', 'type' => 'subflow', 'version' => 1, 'config' => [
                'flow_id'        => $leafFlowId,
                'timeout'        => 'PT1H',
                'output_mapping' => ['middle_answer' => 'answer'],
            ]],
            ['id' => 'm-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [
            ['id' => 'm-e1', 'from' => 'm-sub', 'to' => 'm-end', 'handle' => 'success'],
        ]);

        $parent = $this->runParent(
            set: [],
            subflowConfig: ['output_mapping' => ['top_answer' => 'middle_answer']],
            childFlowId: $middleFlowId,
        );

        $this->assertSame(EndStatus::Success->value, $parent->end_status);
        $this->assertSame('deep', $parent->state['flow']['top_answer'] ?? null);
    }

    public function test_an_invalid_input_name_fails_the_parent_before_any_child_starts(): void
    {
        $childFlowId = $this->childFlow(endStatus: 'success');

        $parent = $this->runParent(
            set: [],
            subflowConfig: ['input_mapping' => ['not a name' => 'x']],
            childFlowId: $childFlowId,
            handle: 'failed',
        );

        $this->assertSame(FlowSessionStatus::Failed, $parent->status);
        $this->assertFalse(FlowSession::query()->where('parent_session_id', $parent->getKey())->exists());
    }

    public function test_validation_refuses_invalid_mapped_names(): void
    {
        $result = $this->app->make(ValidateFlowService::class)->execute([
            ['id' => 'p-sub', 'type' => 'subflow', 'version' => 1, 'config' => [
                'flow_id'        => (string) Str::uuid(),
                'timeout'        => 'PT1H',
                'input_mapping'  => ['id' => '{{flow.x}}'],
                'output_mapping' => ['total' => 'not valid'],
            ]],
        ]);

        $codes = array_map(static fn ($error): string => $error->code, $result->errors);

        $this->assertFalse($result->valid);
        $this->assertContains('subflow_invalid_input_name', $codes);
        $this->assertContains('subflow_invalid_output_source', $codes);
    }

    /**
     * @param  array<string, mixed>  $set
     */
    private function childFlow(string $endStatus, array $set = []): string
    {
        $flowId = (string) Str::uuid();
        $this->createDefinition($flowId, [
            ['id' => 'c-set', 'type' => 'set_variables_test', 'version' => 1, 'config' => ['set' => $set]],
            ['id' => 'c-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => $endStatus]],
        ], [
            ['id' => 'c-e1', 'from' => 'c-set', 'to' => 'c-end', 'handle' => 'default'],
        ]);

        return $flowId;
    }

    /**
     * Runs a parent that sets its variables, calls the child, and on {@code $handle} records `flow.step`.
     *
     * @param  array<string, mixed>  $set
     * @param  array<string, mixed>  $subflowConfig
     */
    private function runParent(array $set, array $subflowConfig, string $childFlowId, string $handle = 'success'): FlowSession
    {
        $edges = [
            ['id' => 'p-e1', 'from' => 'p-set', 'to' => 'p-sub', 'handle' => 'default'],
            ['id' => 'p-e2', 'from' => 'p-sub', 'to' => 'p-after', 'handle' => $handle],
            ['id' => 'p-e3', 'from' => 'p-after', 'to' => 'p-end', 'handle' => 'default'],
        ];

        $definition = $this->createDefinition((string) Str::uuid(), [
            ['id' => 'p-set', 'type' => 'set_variables_test', 'version' => 1, 'config' => ['set' => $set]],
            ['id' => 'p-sub', 'type' => 'subflow', 'version' => 1, 'config' => [
                'flow_id' => $childFlowId,
                'timeout' => 'PT1H',
            ] + $subflowConfig],
            ['id' => 'p-after', 'type' => 'set_variables_test', 'version' => 1, 'config' => ['set' => ['step' => 'after-subflow']]],
            ['id' => 'p-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], $edges);

        try {
            $this->app->make(FlowEngineInterface::class)->start($definition, $this->contact);
        } catch (InvalidNodeConfigException) {
            // A broken node config fails the session and is rethrown; the assertions read the persisted outcome.
        }

        /** @var FlowSession $parent */
        $parent = FlowSession::query()
            ->where('flow_definition_id', $definition->getKey())
            ->firstOrFail();

        return $parent;
    }

    private function childOf(FlowSession $parent): FlowSession
    {
        /** @var FlowSession $child */
        $child = FlowSession::query()->where('parent_session_id', $parent->getKey())->firstOrFail();

        return $child;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $edges
     */
    private function createDefinition(string $flowId, array $nodes, array $edges): FlowDefinition
    {
        return FlowDefinition::query()->create([
            'tenant_id'       => $this->tenantId,
            'flow_id'         => $flowId,
            'version'         => 1,
            'name'            => "Test {$flowId}",
            'nodes'           => $nodes,
            'edges'           => $edges,
            'is_active'       => true,
            'logging_enabled' => false,
        ]);
    }
}

/**
 * Writes each `config.set` entry into `flow.<name>`.
 */
final class SetVariablesTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'set_variables_test';
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
        return 'Set Variables Test';
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
        $set     = is_array($nodeConfig['config']['set'] ?? null) ? $nodeConfig['config']['set'] : [];
        $changes = [];

        foreach ($set as $name => $value) {
            $changes["flow.{$name}"] = $value;
        }

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: 'default',
            stateChanges: $changes,
        );
    }
}
