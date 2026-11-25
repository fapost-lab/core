<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Subflow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\FlowConcurrencyException;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Exceptions\SubflowPinnedVersionMissingException;
use App\Domains\Flow\Handlers\SubflowNodeHandler;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowSessionHistoryEntry;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Services\ValidateFlowService;
use App\Domains\Flow\Subflow\SubflowStarterService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use DateInterval;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\History\HistoryEventType;
use Illuminate\Support\Str;
use RuntimeException;
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
        $registry->register(MarkerTestHandler::class);
        $registry->register(FailedResultTestHandler::class);
        $registry->register(ThrowingTestHandler::class);

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

        $parentFlowId     = (string) Str::uuid();
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

    /**
     * What an event the child emits is filtered by (its assistant) and whom it talks to come from the parent: the
     * starter copies them, nothing else does.
     */
    public function test_the_starter_gives_the_child_the_parents_tenant_assistant_and_contact(): void
    {
        $parent = $this->runParentWithChildNodes(
            [['id' => 'c-mark', 'type' => 'marker_test', 'version' => 1, 'config' => ['marker' => 'child-ran']]],
            [],
        );

        /** @var FlowSession $child */
        $child = FlowSession::query()->where('parent_session_id', $parent->getKey())->firstOrFail();

        $this->assertSame((string) $this->assistant->getKey(), (string) $parent->assistant_id);
        $this->assertSame((string) $parent->assistant_id, (string) $child->assistant_id);
        $this->assertSame((string) $parent->tenant_id, (string) $child->tenant_id);
        $this->assertSame((string) $parent->contact_id, (string) $child->contact_id);
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

        $parentFlowId     = (string) Str::uuid();
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

    public function test_child_that_runs_off_its_graph_returns_to_the_parent_through_the_success_handle(): void
    {
        $parent = $this->runParentWithChildNodes([
            ['id' => 'c-mark', 'type' => 'marker_test', 'version' => 1, 'config' => ['marker' => 'child-ran']],
        ], []);

        $this->assertSame(FlowSessionStatus::Ended, $parent->status);
        $this->assertSame(EndStatus::Success->value, $parent->end_status);
        $this->assertSame('branch-success', $parent->state['flow']['marker'] ?? null);
    }

    public function test_child_whose_node_reports_a_failure_returns_to_the_parent_through_the_failed_handle(): void
    {
        $parent = $this->runParentWithChildNodes([
            ['id' => 'c-fail', 'type' => 'failed_result_test', 'version' => 1, 'config' => []],
        ], []);

        $this->assertSame(FlowSessionStatus::Ended, $parent->status);
        $this->assertSame('branch-failed', $parent->state['flow']['marker'] ?? null);
    }

    public function test_child_whose_handler_throws_in_the_parents_own_tick_routes_the_parent_through_failed_without_rethrowing(): void
    {
        $parent = $this->runParentWithChildNodes([
            ['id' => 'c-boom', 'type' => 'throwing_test', 'version' => 1, 'config' => []],
        ], []);

        $this->assertSame(FlowSessionStatus::Ended, $parent->status);
        $this->assertSame('branch-failed', $parent->state['flow']['marker'] ?? null);
        $this->assertSame(
            FlowSessionStatus::Failed,
            FlowSession::query()->where('parent_session_id', $parent->getKey())->firstOrFail()->status,
        );
    }

    public function test_a_failure_on_the_parents_failed_exit_surfaces_as_its_own_exception_without_a_second_chain(): void
    {
        $childFlowId = (string) Str::uuid();
        $this->createDefinition(
            flowId: $childFlowId,
            nodes: [['id' => 'c-boom', 'type' => 'throwing_test', 'version' => 1, 'config' => ['message' => 'child-boom']]],
            edges: [],
        );
        $parentDefinition = $this->createDefinition(
            flowId: (string) Str::uuid(),
            nodes: [
                ['id' => 'p-sub',  'type' => 'subflow',      'version' => 1, 'config' => ['flow_id' => $childFlowId, 'timeout' => 'PT1H']],
                ['id' => 'p-boom', 'type' => 'throwing_test', 'version' => 1, 'config' => ['message' => 'parent-boom']],
            ],
            edges: [['id' => 'p-e1', 'from' => 'p-sub', 'to' => 'p-boom', 'handle' => 'failed']],
        );

        try {
            $this->app->make(FlowEngineInterface::class)->start($parentDefinition, $this->contact);
            $this->fail('The parent\'s own exception must surface.');
        } catch (RuntimeException $exception) {
            $this->assertSame('parent-boom', $exception->getMessage());
            $this->assertNotInstanceOf(FlowConcurrencyException::class, $exception);
        }

        $parent = FlowSession::query()
            ->where('contact_id', $this->contact->getKey())
            ->whereNull('parent_session_id')
            ->sole();

        $this->assertSame(FlowSessionStatus::Failed, $parent->status);
        $this->assertSame(1, FlowSession::query()->where('parent_session_id', $parent->getKey())->count());
    }

    public function test_a_middle_session_that_runs_off_its_graph_hands_the_outer_parent_the_success_exit(): void
    {
        $leafFlowId = (string) Str::uuid();
        $this->createDefinition(
            flowId: $leafFlowId,
            nodes: [['id' => 'c-fail', 'type' => 'failed_result_test', 'version' => 1, 'config' => []]],
            edges: [],
        );
        // The middle flow has no `failed` edge on its subflow node, so it runs off its graph.
        $middleFlowId = (string) Str::uuid();
        $this->createDefinition(
            flowId: $middleFlowId,
            nodes: [['id' => 'b-sub', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $leafFlowId, 'timeout' => 'PT1H']]],
            edges: [],
        );
        $outerDefinition = $this->createDefinition(
            flowId: (string) Str::uuid(),
            nodes: [
                ['id' => 'a-sub', 'type' => 'subflow',     'version' => 1, 'config' => ['flow_id' => $middleFlowId, 'timeout' => 'PT1H']],
                ['id' => 'a-ok',  'type' => 'marker_test', 'version' => 1, 'config' => ['marker' => 'outer-resumed']],
                ['id' => 'a-end', 'type' => 'end',         'version' => 1, 'config' => ['status' => 'success']],
            ],
            edges: [
                ['id' => 'a-e1', 'from' => 'a-sub', 'to' => 'a-ok',  'handle' => 'success'],
                ['id' => 'a-e2', 'from' => 'a-ok',  'to' => 'a-end', 'handle' => 'default'],
            ],
        );

        $this->app->make(FlowEngineInterface::class)->start($outerDefinition, $this->contact);

        $outer = FlowSession::query()
            ->where('contact_id', $this->contact->getKey())
            ->whereNull('parent_session_id')
            ->sole();

        $this->assertSame(FlowSessionStatus::Ended, $outer->status);
        $this->assertSame('outer-resumed', $outer->state['flow']['marker'] ?? null);
    }

    public function test_a_pinned_subflow_runs_its_version_after_the_child_is_republished(): void
    {
        $childFlowId = (string) Str::uuid();
        $this->createDefinition(
            flowId: $childFlowId,
            nodes: [['id' => 'c-mark', 'type' => 'marker_test', 'version' => 1, 'config' => ['marker' => 'v1']]],
            edges: [],
            isActive: false,
        );
        $this->createDefinition(
            flowId: $childFlowId,
            nodes: [['id' => 'c-mark', 'type' => 'marker_test', 'version' => 1, 'config' => ['marker' => 'v2']]],
            edges: [],
            version: 2,
        );

        $pinned   = $this->runParentCalling($childFlowId, ['flow_version' => 1]);
        $unpinned = $this->runParentCalling($childFlowId, []);

        $this->assertSame(FlowSessionStatus::Ended, $pinned->status);
        $this->assertSame(1, FlowSession::query()->where('parent_session_id', $pinned->getKey())->value('flow_version'));
        $this->assertSame(2, FlowSession::query()->where('parent_session_id', $unpinned->getKey())->value('flow_version'));
    }

    public function test_a_subflow_pinned_to_a_missing_version_fails_the_parent_like_an_inactive_target(): void
    {
        $childFlowId = (string) Str::uuid();
        $this->createDefinition(flowId: $childFlowId, nodes: [], edges: []);

        $parent = $this->runParentCalling($childFlowId, ['flow_version' => 7]);

        $this->assertSame(0, FlowSession::query()->where('parent_session_id', $parent->getKey())->count());
        $this->assertSame(FlowSessionStatus::Failed, $parent->status);
    }

    public function test_the_starter_names_a_missing_pinned_version_apart_from_an_inactive_target(): void
    {
        $childFlowId = (string) Str::uuid();
        $this->createDefinition(
            flowId: $childFlowId,
            nodes: [['id' => 'c-mark', 'type' => 'marker_test', 'version' => 1, 'config' => ['marker' => 'v1']]],
            edges: [],
        );
        $parent = $this->runParentCalling($childFlowId, []);

        $starter = $this->app->make(SubflowStarterService::class);

        $this->expectException(SubflowPinnedVersionMissingException::class);
        $starter->start($parent, $childFlowId, new DateInterval('PT1H'), 'p-sub', [], 9);
    }

    public function test_the_handler_reports_a_missing_pinned_version_with_its_own_error_type(): void
    {
        $childFlowId = (string) Str::uuid();
        $this->createDefinition(
            flowId: $childFlowId,
            nodes: [['id' => 'c-mark', 'type' => 'marker_test', 'version' => 1, 'config' => ['marker' => 'v1']]],
            edges: [],
        );
        $parent = $this->runParentCalling($childFlowId, []);

        $result = $this->app->make(SubflowNodeHandler::class)->execute(
            $this->subflowNode($childFlowId, ['flow_version' => 7]),
            [],
            $this->contextFor($parent),
        );

        $this->assertSame(NodeExecutionStatus::Failed, $result->status);
        $this->assertSame('subflow_pinned_version_missing', $result->metadata['error_type'] ?? null);
    }

    public function test_the_handler_rejects_a_malformed_stored_pin(): void
    {
        $childFlowId = (string) Str::uuid();
        $this->createDefinition(
            flowId: $childFlowId,
            nodes: [['id' => 'c-mark', 'type' => 'marker_test', 'version' => 1, 'config' => ['marker' => 'v1']]],
            edges: [],
        );
        $parent = $this->runParentCalling($childFlowId, []);

        $this->expectException(InvalidNodeConfigException::class);
        $this->app->make(SubflowNodeHandler::class)->execute(
            $this->subflowNode($childFlowId, ['flow_version' => 'two']),
            [],
            $this->contextFor($parent),
        );
    }

    public function test_a_pin_never_resolves_to_a_template(): void
    {
        $templateFlowId = (string) Str::uuid();
        FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => $templateFlowId,
            'version'   => 1,
            'kind'      => FlowKind::Template->value,
            'name'      => 'Template',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        $parent = $this->runParentCalling($templateFlowId, ['flow_version' => 1]);

        $this->assertSame(FlowSessionStatus::Failed, $parent->status);
        $this->assertSame(0, FlowSession::query()->where('parent_session_id', $parent->getKey())->count());
    }

    public function test_a_pin_never_resolves_to_another_flows_version(): void
    {
        $childFlowId = (string) Str::uuid();
        $this->createDefinition(flowId: $childFlowId, nodes: [], edges: []);
        $this->createDefinition(flowId: (string) Str::uuid(), nodes: [], edges: [], version: 2);

        $parent = $this->runParentCalling($childFlowId, ['flow_version' => 2]);

        $this->assertSame(FlowSessionStatus::Failed, $parent->status);
    }

    public function test_validation_refuses_a_pin_to_a_version_the_target_never_published_and_malformed_pins(): void
    {
        $childFlowId = (string) Str::uuid();
        $this->createDefinition(flowId: $childFlowId, nodes: [], edges: []);
        $this->createDefinition(flowId: (string) Str::uuid(), nodes: [], edges: [], version: 2);

        $codes = fn (mixed $pin): array => array_map(
            static fn ($error): string => $error->code,
            $this->app->make(ValidateFlowService::class)->execute(
                nodes: [[
                    'id'      => 'p-sub',
                    'type'    => 'subflow',
                    'version' => 1,
                    'config'  => ['flow_id' => $childFlowId, 'timeout' => 'PT1H', 'flow_version' => $pin],
                ]],
                edges: [],
                flowId: (string) Str::uuid(),
            )->errors,
        );

        $this->assertNotContains('subflow_pinned_version_missing', $codes(1));
        $this->assertNotContains('subflow_pinned_version_missing', $codes(null));
        $this->assertNotContains('subflow_pinned_version_missing', $codes(''));
        $this->assertContains('subflow_pinned_version_missing', $codes(2));
        $this->assertContains('subflow_invalid_flow_version', $codes(0));
        $this->assertContains('subflow_invalid_flow_version', $codes('latest'));
    }

    /**
     * @param  array<string, mixed>  $extraConfig
     *
     * @return array<string, mixed>
     */
    private function subflowNode(string $childFlowId, array $extraConfig): array
    {
        return [
            'id'      => 'p-sub',
            'type'    => 'subflow',
            'version' => 1,
            'config'  => ['flow_id' => $childFlowId, 'timeout' => 'PT1H', ...$extraConfig],
        ];
    }

    private function contextFor(FlowSession $parent): NodeExecutionContext
    {
        return new NodeExecutionContext(
            tenantId: $this->tenantId,
            contactId: (string) $parent->contact_id,
            sessionId: (string) $parent->getKey(),
            nodeId: 'p-sub',
            idempotencyKey: 'test',
            platform: 'telegram',
        );
    }

    /**
     * Starts a parent whose subflow node calls the given child with extra config; the parent marks each exit.
     *
     * @param  array<string, mixed>  $extraConfig
     */
    private function runParentCalling(string $childFlowId, array $extraConfig): FlowSession
    {
        $parentDefinition = $this->createDefinition(
            flowId: (string) Str::uuid(),
            nodes: [
                ['id' => 'p-sub', 'type' => 'subflow',     'version' => 1, 'config' => ['flow_id' => $childFlowId, 'timeout' => 'PT1H', ...$extraConfig]],
                ['id' => 'p-ok',  'type' => 'marker_test', 'version' => 1, 'config' => ['marker' => 'branch-success']],
                ['id' => 'p-bad', 'type' => 'marker_test', 'version' => 1, 'config' => ['marker' => 'branch-failed']],
                ['id' => 'p-end', 'type' => 'end',         'version' => 1, 'config' => ['status' => 'success']],
            ],
            edges: [
                ['id' => 'p-e1', 'from' => 'p-sub', 'to' => 'p-ok',  'handle' => 'success'],
                ['id' => 'p-e2', 'from' => 'p-sub', 'to' => 'p-bad', 'handle' => 'failed'],
                ['id' => 'p-e3', 'from' => 'p-ok',  'to' => 'p-end', 'handle' => 'default'],
                ['id' => 'p-e4', 'from' => 'p-bad', 'to' => 'p-end', 'handle' => 'default'],
            ],
        );

        $this->app->make(FlowEngineInterface::class)->start($parentDefinition, $this->contact);

        return FlowSession::query()
            ->where('flow_definition_id', $parentDefinition->getKey())
            ->whereNull('parent_session_id')
            ->latest('created_at')
            ->firstOrFail();
    }

    /**
     * Starts a parent whose subflow node calls a child made of the given nodes; the parent has a marker on
     * each of its success / failed exits.
     *
     * @param  array<int, array<string, mixed>>  $childNodes
     * @param  array<int, array<string, mixed>>  $childEdges
     */
    private function runParentWithChildNodes(array $childNodes, array $childEdges): FlowSession
    {
        $childFlowId = (string) Str::uuid();
        $this->createDefinition(flowId: $childFlowId, nodes: $childNodes, edges: $childEdges);

        $parentDefinition = $this->createDefinition(
            flowId: (string) Str::uuid(),
            nodes: [
                ['id' => 'p-sub', 'type' => 'subflow',     'version' => 1, 'config' => ['flow_id' => $childFlowId, 'timeout' => 'PT1H']],
                ['id' => 'p-ok',  'type' => 'marker_test', 'version' => 1, 'config' => ['marker' => 'branch-success']],
                ['id' => 'p-bad', 'type' => 'marker_test', 'version' => 1, 'config' => ['marker' => 'branch-failed']],
                ['id' => 'p-end', 'type' => 'end',         'version' => 1, 'config' => ['status' => 'success']],
            ],
            edges: [
                ['id' => 'p-e1', 'from' => 'p-sub', 'to' => 'p-ok',  'handle' => 'success'],
                ['id' => 'p-e2', 'from' => 'p-sub', 'to' => 'p-bad', 'handle' => 'failed'],
                ['id' => 'p-e3', 'from' => 'p-ok',  'to' => 'p-end', 'handle' => 'default'],
                ['id' => 'p-e4', 'from' => 'p-bad', 'to' => 'p-end', 'handle' => 'default'],
            ],
        );

        $this->app->make(FlowEngineInterface::class)->start($parentDefinition, $this->contact);

        return FlowSession::query()
            ->where('contact_id', $this->contact->getKey())
            ->whereNull('parent_session_id')
            ->latest('created_at')
            ->firstOrFail();
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
        int $version = 1,
        bool $isActive = true,
    ): FlowDefinition {
        return FlowDefinition::query()->create([
            'tenant_id'       => $this->tenantId,
            'flow_id'         => $flowId,
            'version'         => $version,
            'name'            => "Test {$flowId}",
            'nodes'           => $nodes,
            'edges'           => $edges,
            'is_active'       => $isActive,
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
        return 'Logic';
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


final class FailedResultTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'failed_result_test';
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
        return 'Failed Result Test';
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
        return new NodeExecutionResult(status: NodeExecutionStatus::Failed);
    }
}

final class ThrowingTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'throwing_test';
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
        return 'Throwing Test';
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
        $message = $nodeConfig['config']['message'] ?? 'boom';

        throw new RuntimeException(is_string($message) ? $message : 'boom');
    }
}
