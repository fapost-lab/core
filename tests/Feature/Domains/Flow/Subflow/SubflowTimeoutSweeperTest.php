<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Subflow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Subflow\SubflowTimeoutSweeper;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * Verifies the per-tenant maintenance sweep that recovers stuck subflow chains:
 * expired parent + live child → child force-failed + parent advanced via the
 * `failed` handle; expired parent without a child → parent marked expired.
 */
final class SubflowTimeoutSweeperTest extends FeatureTestCase
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

        $this->assistant = Assistant::factory()->create([
            'tenant_id'        => $this->tenantId,
            'default_language' => 'en',
        ]);
        $this->contact = Contact::factory()->forTenant($this->tenantId)->create();
        $this->app->make(CurrentAssistantInterface::class)->set($this->assistant);
    }

    public function test_expired_parent_with_live_child_force_fails_child_and_resumes_parent_via_failed_handle(): void
    {
        $childDefinition = $this->createDefinition([
            ['id' => 'c-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], []);
        $parentDefinition = $this->createDefinition(
            nodes: [
                ['id' => 'p-sub',  'type' => 'subflow',     'version' => 1, 'config' => ['flow_id' => $childDefinition->flow_id, 'timeout' => 'PT1H']],
                ['id' => 'p-fail', 'type' => 'end',         'version' => 1, 'config' => ['status' => 'failed']],
            ],
            edges: [
                ['id' => 'e1', 'from' => 'p-sub', 'to' => 'p-fail', 'handle' => 'failed'],
            ],
        );

        $parent = $this->createSession([
            'flow_definition_id' => $parentDefinition->getKey(),
            'current_node_id'    => 'p-sub',
            'status'             => FlowSessionStatus::PausedSubflow,
            'expires_at'         => Carbon::now()->subMinutes(5),
        ]);

        $child = $this->createSession([
            'flow_definition_id'    => $childDefinition->getKey(),
            'current_node_id'       => 'c-end',
            'status'                => FlowSessionStatus::WaitingInput,
            'parent_session_id'     => $parent->getKey(),
            'parent_resume_node_id' => 'p-sub',
        ]);

        $report = $this->app->make(SubflowTimeoutSweeper::class)->sweep();

        $this->assertSame(1, $report['forced_failures']);
        $this->assertSame(0, $report['orphans_expired']);

        $child->refresh();
        $this->assertSame(FlowSessionStatus::Ended, $child->status);
        $this->assertSame(EndStatus::Failed->value, $child->end_status);

        $parent->refresh();
        $this->assertSame(FlowSessionStatus::Ended, $parent->status);
        $this->assertSame(EndStatus::Failed->value, $parent->end_status);
    }

    public function test_expired_parent_without_live_child_is_marked_expired(): void
    {
        $definition = $this->createDefinition([
            ['id' => 'p-sub', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => 'orphan', 'timeout' => 'PT1H']],
        ], []);

        $parent = $this->createSession([
            'flow_definition_id' => $definition->getKey(),
            'current_node_id'    => 'p-sub',
            'status'             => FlowSessionStatus::PausedSubflow,
            'expires_at'         => Carbon::now()->subMinutes(5),
        ]);

        $report = $this->app->make(SubflowTimeoutSweeper::class)->sweep();

        $this->assertSame(0, $report['forced_failures']);
        $this->assertSame(1, $report['orphans_expired']);

        $parent->refresh();
        $this->assertSame(FlowSessionStatus::Expired, $parent->status);
        $this->assertNull($parent->current_node_id);
    }

    public function test_paused_subflow_parent_not_yet_expired_is_left_alone(): void
    {
        $definition = $this->createDefinition([
            ['id' => 'p-sub', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => 'still-running', 'timeout' => 'PT1H']],
        ], []);

        $parent = $this->createSession([
            'flow_definition_id' => $definition->getKey(),
            'current_node_id'    => 'p-sub',
            'status'             => FlowSessionStatus::PausedSubflow,
            'expires_at'         => Carbon::now()->addHour(),
        ]);

        $report = $this->app->make(SubflowTimeoutSweeper::class)->sweep();

        $this->assertSame(0, $report['forced_failures']);
        $this->assertSame(0, $report['orphans_expired']);

        $parent->refresh();
        $this->assertSame(FlowSessionStatus::PausedSubflow, $parent->status);
    }

    public function test_expired_parent_whose_child_sleeps_in_a_delay_is_not_expired_as_an_orphan(): void
    {
        $parentDefinition = $this->failedExitParentDefinition();
        $childDefinition  = $this->createDefinition([
            ['id' => 'c-delay', 'type' => 'delay', 'version' => 1, 'config' => []],
        ], []);

        $parent = $this->createSession([
            'flow_definition_id' => $parentDefinition->getKey(),
            'current_node_id'    => 'p-sub',
            'status'             => FlowSessionStatus::PausedSubflow,
            'expires_at'         => Carbon::now()->subMinutes(5),
        ]);
        $child = $this->createSession([
            'flow_definition_id' => $childDefinition->getKey(),
            'current_node_id'    => 'c-delay',
            'status'             => FlowSessionStatus::Paused,
            'parent_session_id'  => $parent->getKey(),
        ]);

        $report = $this->app->make(SubflowTimeoutSweeper::class)->sweep();

        $this->assertSame(1, $report['forced_failures']);
        $this->assertSame(0, $report['orphans_expired']);
        $this->assertSame(EndStatus::Failed->value, $child->refresh()->end_status);
        $this->assertSame(FlowSessionStatus::Ended, $parent->refresh()->status);
    }

    public function test_a_nested_chain_unwinds_bottom_up_instead_of_expiring_the_top_parent_as_an_orphan(): void
    {
        $topDefinition  = $this->failedExitParentDefinition();
        $midDefinition  = $this->failedExitParentDefinition();
        $leafDefinition = $this->createDefinition([
            ['id' => 'l1', 'type' => 'input', 'version' => 1, 'config' => []],
        ], []);

        $top = $this->createSession([
            'flow_definition_id' => $topDefinition->getKey(),
            'current_node_id'    => 'p-sub',
            'status'             => FlowSessionStatus::PausedSubflow,
            'expires_at'         => Carbon::now()->subMinutes(5),
            'created_at'         => Carbon::now()->subMinutes(30),
        ]);
        $mid = $this->createSession([
            'flow_definition_id' => $midDefinition->getKey(),
            'current_node_id'    => 'p-sub',
            'status'             => FlowSessionStatus::PausedSubflow,
            'expires_at'         => Carbon::now()->subMinutes(5),
            'parent_session_id'  => $top->getKey(),
            'created_at'         => Carbon::now()->subMinutes(20),
        ]);
        $leaf = $this->createSession([
            'flow_definition_id' => $leafDefinition->getKey(),
            'current_node_id'    => 'l1',
            'status'             => FlowSessionStatus::WaitingInput,
            'parent_session_id'  => $mid->getKey(),
            'created_at'         => Carbon::now()->subMinutes(10),
        ]);

        $report = $this->app->make(SubflowTimeoutSweeper::class)->sweep();

        $this->assertSame(0, $report['orphans_expired']);
        $this->assertSame(EndStatus::Failed->value, $leaf->refresh()->end_status);
        $this->assertSame(FlowSessionStatus::Ended, $mid->refresh()->status);
        $this->assertSame(FlowSessionStatus::Ended, $top->refresh()->status);
        $this->assertSame(EndStatus::Failed->value, $top->end_status);
    }

    public function test_an_orphan_that_is_itself_a_child_hands_its_own_parent_the_failed_exit(): void
    {
        $topDefinition = $this->failedExitParentDefinition();
        $midDefinition = $this->createDefinition([
            ['id' => 'p-sub', 'type' => 'subflow', 'version' => 1, 'config' => ['timeout' => 'PT1H']],
        ], []);

        $top = $this->createSession([
            'flow_definition_id' => $topDefinition->getKey(),
            'current_node_id'    => 'p-sub',
            'status'             => FlowSessionStatus::PausedSubflow,
            'expires_at'         => Carbon::now()->addHour(),
        ]);
        $mid = $this->createSession([
            'flow_definition_id' => $midDefinition->getKey(),
            'current_node_id'    => 'p-sub',
            'status'             => FlowSessionStatus::PausedSubflow,
            'expires_at'         => Carbon::now()->subMinutes(5),
            'parent_session_id'  => $top->getKey(),
        ]);

        $this->app->make(SubflowTimeoutSweeper::class)->sweep();

        $this->assertSame(FlowSessionStatus::Expired, $mid->refresh()->status);
        $this->assertSame(FlowSessionStatus::Ended, $top->refresh()->status);
        $this->assertSame(EndStatus::Failed->value, $top->end_status);
    }

    private function failedExitParentDefinition(): FlowDefinition
    {
        return $this->createDefinition(
            nodes: [
                ['id' => 'p-sub', 'type' => 'subflow', 'version' => 1, 'config' => ['timeout' => 'PT1H']],
                ['id' => 'p-fail', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'failed']],
            ],
            edges: [['id' => 'e1', 'from' => 'p-sub', 'to' => 'p-fail', 'handle' => 'failed']],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $edges
     */
    private function createDefinition(array $nodes, array $edges): FlowDefinition
    {
        return FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'sweeper test flow',
            'nodes'     => $nodes,
            'edges'     => $edges,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSession(array $overrides): FlowSession
    {
        return FlowSession::query()->create(array_merge([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'contact_id'   => $this->contact->getKey(),
            'flow_version' => 1,
            'state'        => [],
            'version'      => 1,
        ], $overrides));
    }
}
