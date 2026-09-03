<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Subflow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowSessionHistoryEntry;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\History\HistoryEventType;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * End-to-end coverage of the subflow lifecycle: parent invokes child,
 * child reaches an `end` node, the {@see \App\Domains\Flow\Subflow\DefaultSubflowResumer}
 * resumes the parent on the matching success/cancelled/failed handle, and
 * the parent runs to its own end.
 *
 * Uses real DI wiring — engine, persister, lock, callgraph repo all real.
 * Inbound channel / send_message are not exercised; the test flows use a
 * stateless marker handler.
 */
final class SubflowLifecycleTest extends FeatureTestCase
{
    private string $tenantId;

    private Assistant $assistant;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(
            new RuntimeTenant(id: $this->tenantId, schemaName: 'main'),
        );

        $registry = $this->app->make(NodeHandlerRegistry::class);
        $registry->register(new MarkerTestHandler());

        $this->assistant = Assistant::factory()->create([
            'tenant_id'        => $this->tenantId,
            'default_language' => 'en',
        ]);
        $this->contact = Contact::factory()->forTenant($this->tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($this->assistant);
    }

    public function test_subflow_success_chain_runs_parent_to_completion_via_success_handle(): void
    {
        $childFlowId = (string) Str::uuid();
        $this->createDefinition(
            flowId: $childFlowId,
            nodes: [
                ['id' => 'c-mark', 'type' => 'marker_test', 'version' => 1, 'config' => ['marker' => 'child-ran']],
                ['id' => 'c-end',  'type' => 'end',         'version' => 1, 'config' => ['status' => 'success']],
            ],
            edges: [
                ['id' => 'c-e1', 'from' => 'c-mark', 'to' => 'c-end', 'handle' => 'default'],
            ],
        );

        $parentFlowId   = (string) Str::uuid();
        $parentDefinition = $this->createDefinition(
            flowId: $parentFlowId,
            nodes: [
                ['id' => 'p-pre',  'type' => 'marker_test', 'version' => 1, 'config' => ['marker' => 'parent-pre']],
                ['id' => 'p-sub',  'type' => 'subflow',     'version' => 1, 'config' => ['flow_id' => $childFlowId, 'timeout' => 'PT1H']],
                ['id' => 'p-post', 'type' => 'marker_test', 'version' => 1, 'config' => ['marker' => 'parent-post']],
                ['id' => 'p-end',  'type' => 'end',         'version' => 1, 'config' => ['status' => 'success']],
            ],
            edges: [
                ['id' => 'p-e1', 'from' => 'p-pre',  'to' => 'p-sub',  'handle' => 'default'],
                ['id' => 'p-e2', 'from' => 'p-sub',  'to' => 'p-post', 'handle' => 'success'],
                ['id' => 'p-e3', 'from' => 'p-post', 'to' => 'p-end',  'handle' => 'default'],
            ],
        );

        $engine = $this->app->make(FlowEngineInterface::class);
        $engine->start($parentDefinition, $this->contact);

        /** @var FlowSession $parent */
        $parent = FlowSession::query()
            ->where('contact_id', $this->contact->getKey())
            ->whereNull('parent_session_id')
            ->latest('created_at')
            ->first();

        /** @var FlowSession $child */
        $child = FlowSession::query()
            ->where('parent_session_id', $parent->getKey())
            ->latest('created_at')
            ->first();

        $this->assertNotNull($parent);
        $this->assertNotNull($child);

        $this->assertSame(FlowSessionStatus::Ended, $child->status);
        $this->assertSame(EndStatus::Success->value, $child->end_status);

        $this->assertSame(FlowSessionStatus::Ended, $parent->status, 'parent should finish via success handle after child end');
        $this->assertSame(EndStatus::Success->value, $parent->end_status);
        $this->assertSame('parent-post', $parent->state['flow']['marker'] ?? null);
    }

    public function test_subflow_failed_routes_parent_through_failed_handle(): void
    {
        $childFlowId = (string) Str::uuid();
        $this->createDefinition(
            flowId: $childFlowId,
            nodes: [
                ['id' => 'c-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'failed']],
            ],
            edges: [],
        );

        $parentFlowId   = (string) Str::uuid();
        $parentDefinition = $this->createDefinition(
            flowId: $parentFlowId,
            nodes: [
                ['id' => 'p-sub',  'type' => 'subflow',     'version' => 1, 'config' => ['flow_id' => $childFlowId, 'timeout' => 'PT1H']],
                ['id' => 'p-fail', 'type' => 'marker_test', 'version' => 1, 'config' => ['marker' => 'failure-branch']],
                ['id' => 'p-end',  'type' => 'end',         'version' => 1, 'config' => ['status' => 'failed']],
            ],
            edges: [
                ['id' => 'p-e1', 'from' => 'p-sub',  'to' => 'p-fail', 'handle' => 'failed'],
                ['id' => 'p-e2', 'from' => 'p-fail', 'to' => 'p-end',  'handle' => 'default'],
            ],
        );

        $this->app->make(FlowEngineInterface::class)->start($parentDefinition, $this->contact);

        /** @var FlowSession $parent */
        $parent = FlowSession::query()
            ->where('contact_id', $this->contact->getKey())
            ->whereNull('parent_session_id')
            ->latest('created_at')
            ->first();

        $this->assertSame(FlowSessionStatus::Ended, $parent->status);
        $this->assertSame(EndStatus::Failed->value, $parent->end_status);
        $this->assertSame('failure-branch', $parent->state['flow']['marker'] ?? null);
    }

    public function test_subflow_cancelled_routes_parent_through_cancelled_handle(): void
    {
        $childFlowId = (string) Str::uuid();
        $this->createDefinition(
            flowId: $childFlowId,
            nodes: [
                ['id' => 'c-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'cancelled']],
            ],
            edges: [],
        );

        $parentFlowId     = (string) Str::uuid();
        $parentDefinition = $this->createDefinition(
            flowId: $parentFlowId,
            nodes: [
                ['id' => 'p-sub',    'type' => 'subflow',     'version' => 1, 'config' => ['flow_id' => $childFlowId, 'timeout' => 'PT1H']],
                ['id' => 'p-cancel', 'type' => 'marker_test', 'version' => 1, 'config' => ['marker' => 'cancel-branch']],
                ['id' => 'p-end',    'type' => 'end',         'version' => 1, 'config' => ['status' => 'cancelled']],
            ],
            edges: [
                ['id' => 'p-e1', 'from' => 'p-sub',    'to' => 'p-cancel', 'handle' => 'cancelled'],
                ['id' => 'p-e2', 'from' => 'p-cancel', 'to' => 'p-end',    'handle' => 'default'],
            ],
        );

        $this->app->make(FlowEngineInterface::class)->start($parentDefinition, $this->contact);

        /** @var FlowSession $parent */
        $parent = FlowSession::query()
            ->where('contact_id', $this->contact->getKey())
            ->whereNull('parent_session_id')
            ->latest('created_at')
            ->first();

        /** @var FlowSession $child */
        $child = FlowSession::query()
            ->where('parent_session_id', $parent->getKey())
            ->latest('created_at')
            ->first();

        $this->assertSame(FlowSessionStatus::Ended, $child->status);
        $this->assertSame(EndStatus::Cancelled->value, $child->end_status);

        $this->assertSame(FlowSessionStatus::Ended, $parent->status);
        $this->assertSame(EndStatus::Cancelled->value, $parent->end_status);
        $this->assertSame('cancel-branch', $parent->state['flow']['marker'] ?? null, 'parent should route through the cancelled handle');
    }

    public function test_subflow_writes_started_and_returned_history_events_when_logging_enabled(): void
    {
        $childFlowId = (string)Str::uuid();
        $this->createDefinition(
            flowId: $childFlowId,
            nodes: [
                ['id' => 'c-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            edges: [],
        );

        $parentFlowId     = (string)Str::uuid();
        $parentDefinition = $this->createDefinition(
            flowId: $parentFlowId,
            nodes: [
                [
                    'id'      => 'p-sub',
                    'type'    => 'subflow',
                    'version' => 1,
                    'config'  => ['flow_id' => $childFlowId, 'timeout' => 'PT1H'],
                ],
                ['id' => 'p-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            edges: [
                ['id' => 'p-e1', 'from' => 'p-sub', 'to' => 'p-end', 'handle' => 'success'],
            ],
            loggingEnabled: true,
        );

        $this->app->make(FlowEngineInterface::class)->start($parentDefinition, $this->contact);

        /** @var FlowSession $parent */
        $parent = FlowSession::query()
            ->where('contact_id', $this->contact->getKey())
            ->whereNull('parent_session_id')
            ->latest('created_at')
            ->first();

        /** @var FlowSession $child */
        $child = FlowSession::query()
            ->where('parent_session_id', $parent->getKey())
            ->latest('created_at')
            ->first();

        $started = FlowSessionHistoryEntry::query()
            ->where('session_id', $parent->getKey())
            ->where('event_type', HistoryEventType::SubflowStarted->value)
            ->first();

        $returned = FlowSessionHistoryEntry::query()
            ->where('session_id', $parent->getKey())
            ->where('event_type', HistoryEventType::SubflowReturned->value)
            ->first();

        $this->assertNotNull($started, 'SubflowStarted event must be recorded in parent history');
        $this->assertSame('p-sub', $started->node_id);
        $this->assertSame((string)$child->getKey(), $started->metadata['child_session_id'] ?? null);

        $this->assertNotNull($returned, 'SubflowReturned event must be recorded in parent history');
        $this->assertSame('p-sub', $returned->node_id);
        $this->assertSame((string)$child->getKey(), $returned->metadata['child_session_id'] ?? null);
        $this->assertSame(EndStatus::Success->value, $returned->metadata['end_status'] ?? null);
    }

    public function test_subflow_does_not_write_history_events_when_logging_disabled(): void
    {
        $childFlowId = (string)Str::uuid();
        $this->createDefinition(
            flowId: $childFlowId,
            nodes: [
                ['id' => 'c-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            edges: [],
        );

        $parentFlowId     = (string)Str::uuid();
        $parentDefinition = $this->createDefinition(
            flowId: $parentFlowId,
            nodes: [
                [
                    'id'      => 'p-sub',
                    'type'    => 'subflow',
                    'version' => 1,
                    'config'  => ['flow_id' => $childFlowId, 'timeout' => 'PT1H'],
                ],
                ['id' => 'p-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            edges: [
                ['id' => 'p-e1', 'from' => 'p-sub', 'to' => 'p-end', 'handle' => 'success'],
            ],
            loggingEnabled: false,
        );

        $this->app->make(FlowEngineInterface::class)->start($parentDefinition, $this->contact);

        /** @var FlowSession $parent */
        $parent = FlowSession::query()
            ->where('contact_id', $this->contact->getKey())
            ->whereNull('parent_session_id')
            ->latest('created_at')
            ->first();

        $count = FlowSessionHistoryEntry::query()
            ->where('session_id', $parent->getKey())
            ->whereIn('event_type', [
                HistoryEventType::SubflowStarted->value,
                HistoryEventType::SubflowReturned->value,
            ])
            ->count();

        $this->assertSame(0, $count, 'No history events should be written when logging is disabled');
    }

    public function test_subflow_with_inactive_callee_marks_parent_session_failed(): void
    {
        $missingFlowId    = (string) Str::uuid();
        $parentFlowId     = (string) Str::uuid();
        $parentDefinition = $this->createDefinition(
            flowId: $parentFlowId,
            nodes: [
                ['id' => 'p-sub', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $missingFlowId, 'timeout' => 'PT1H']],
                ['id' => 'p-end', 'type' => 'end',     'version' => 1, 'config' => ['status' => 'success']],
            ],
            edges: [
                ['id' => 'p-e1', 'from' => 'p-sub', 'to' => 'p-end', 'handle' => 'success'],
            ],
        );

        $this->app->make(FlowEngineInterface::class)->start($parentDefinition, $this->contact);

        /** @var FlowSession $parent */
        $parent = FlowSession::query()
            ->where('contact_id', $this->contact->getKey())
            ->whereNull('parent_session_id')
            ->latest('created_at')
            ->first();

        $this->assertSame(FlowSessionStatus::Failed, $parent->status);
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $edges
     */
    private function createDefinition(
        string $flowId,
        array $nodes,
        array $edges,
        bool $loggingEnabled = false,
    ): FlowDefinition {
        return FlowDefinition::query()->create([
            'tenant_id'       => $this->tenantId,
            'flow_id'         => $flowId,
            'version'         => 1,
            'name'            => "Test {$flowId}",
            'nodes'           => $nodes,
            'edges'           => $edges,
            'is_active'       => true,
            'logging_enabled' => $loggingEnabled,
        ]);
    }
}

/**
 * Pure handler that records {@code config.marker} into {@code flow.marker}.
 * Used by subflow lifecycle tests as a position witness — assertions check
 * which branch of the flow ran by inspecting the persisted state.
 */
final class MarkerTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'marker_test';
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
        return 'Marker Test';
    }

    public function category(): string
    {
        return 'Test';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $marker = $nodeConfig['config']['marker'] ?? null;

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: 'default',
            stateChanges: is_string($marker) && '' !== $marker
                ? ['flow.marker' => $marker]
                : [],
        );
    }
}
