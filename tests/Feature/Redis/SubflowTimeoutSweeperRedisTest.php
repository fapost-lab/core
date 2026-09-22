<?php

declare(strict_types=1);

namespace Tests\Feature\Redis;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Concurrency\LockHandle;
use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Concurrency\SessionLockManager;
use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Subflow\SubflowTimeoutSweeper;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\FeatureTestCase;

/**
 * The subflow timeout sweeper touches a parent and its child only under the
 * parent's session lock — the one an inbound message for the same contact
 * takes — and skips a parent whose lock another worker holds.
 */
#[Group('redis')]
final class SubflowTimeoutSweeperRedisTest extends FeatureTestCase
{
    use InteractsWithRedisLocks;

    private string $tenantId;

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the busy-lock case fast: the default budget is 3 * 2s.
        config([
            'flow.lock.acquisition_retries' => 1,
            'flow.lock.retry_delay_ms'      => 50,
        ]);

        $this->tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(
            new RuntimeTenant(id: $this->tenantId, schemaName: 'main'),
        );

        $this->assistant = Assistant::factory()->create([
            'tenant_id'        => $this->tenantId,
            'default_language' => 'en',
        ]);
        $this->app->make(CurrentAssistantInterface::class)->set($this->assistant);
    }

    public function test_busy_lock_leaves_the_parent_and_its_live_child_untouched(): void
    {
        [$parent, $child] = $this->expiredParentWithLiveChild($this->newContact());
        $holder           = $this->holdLockOf($parent);

        $report = $this->app->make(SubflowTimeoutSweeper::class)->sweep();

        $this->assertSame(0, $report['forced_failures']);
        $this->assertSame(FlowSessionStatus::WaitingInput, $child->refresh()->status);
        $this->assertSame(FlowSessionStatus::PausedSubflow, $parent->refresh()->status);
        $this->assertSame($holder->token, $this->redisGet($holder->key));
    }

    public function test_busy_lock_does_not_mark_an_orphaned_parent_expired(): void
    {
        $parent = $this->expiredOrphanParent($this->newContact());
        $this->holdLockOf($parent);

        $report = $this->app->make(SubflowTimeoutSweeper::class)->sweep();

        $this->assertSame(0, $report['orphans_expired']);
        $this->assertSame(FlowSessionStatus::PausedSubflow, $parent->refresh()->status);
    }

    public function test_a_busy_parent_is_skipped_and_the_others_are_still_swept(): void
    {
        $busy = $this->expiredOrphanParent($this->newContact());
        $free = $this->expiredOrphanParent($this->newContact());
        $this->holdLockOf($busy);

        $report = $this->app->make(SubflowTimeoutSweeper::class)->sweep();

        $this->assertSame(1, $report['orphans_expired']);
        $this->assertSame(FlowSessionStatus::PausedSubflow, $busy->refresh()->status);
        $this->assertSame(FlowSessionStatus::Expired, $free->refresh()->status);
    }

    public function test_free_lock_force_fails_the_child_resumes_the_parent_and_releases_the_lock(): void
    {
        [$parent, $child] = $this->expiredParentWithLiveChild($this->newContact());
        $key              = $this->scopeOf($parent)->key();
        $this->trackLockKey($key);

        $report = $this->app->make(SubflowTimeoutSweeper::class)->sweep();

        $this->assertSame(1, $report['forced_failures']);
        $this->assertSame(EndStatus::Failed->value, $child->refresh()->end_status);
        $this->assertSame(FlowSessionStatus::Ended, $parent->refresh()->status);
        $this->assertSame(EndStatus::Failed->value, $parent->end_status);
        $this->assertNull($this->redisGet($key), 'The sweeper must release the lock it took.');
    }

    private function holdLockOf(FlowSession $parent): LockHandle
    {
        $scope = $this->scopeOf($parent);
        $this->trackLockKey($scope->key());

        $holder = $this->app->make(SessionLockManager::class)->acquire($scope, ttlSeconds: 30);
        $this->assertNotNull($holder, 'Precondition: another worker must hold the session lock.');

        return $holder;
    }

    private function scopeOf(FlowSession $session): LockScope
    {
        return new LockScope(
            tenantId: (string) $session->tenant_id,
            contactId: (string) $session->contact_id,
            assistantId: (string) $session->assistant_id,
        );
    }

    /**
     * @return array{0: FlowSession, 1: FlowSession}
     */
    private function expiredParentWithLiveChild(Contact $contact): array
    {
        $childDefinition = $this->createDefinition([
            ['id' => 'c-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], []);
        $parentDefinition = $this->createDefinition(
            nodes: [
                ['id' => 'p-sub',  'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $childDefinition->flow_id, 'timeout' => 'PT1H']],
                ['id' => 'p-fail', 'type' => 'end',     'version' => 1, 'config' => ['status' => 'failed']],
            ],
            edges: [
                ['id' => 'e1', 'from' => 'p-sub', 'to' => 'p-fail', 'handle' => 'failed'],
            ],
        );

        $parent = $this->createSession($contact, [
            'flow_definition_id' => $parentDefinition->getKey(),
            'current_node_id'    => 'p-sub',
            'status'             => FlowSessionStatus::PausedSubflow,
            'expires_at'         => Carbon::now()->subMinutes(5),
        ]);

        $child = $this->createSession($contact, [
            'flow_definition_id'    => $childDefinition->getKey(),
            'current_node_id'       => 'c-end',
            'status'                => FlowSessionStatus::WaitingInput,
            'parent_session_id'     => $parent->getKey(),
            'parent_resume_node_id' => 'p-sub',
        ]);

        return [$parent, $child];
    }

    private function expiredOrphanParent(Contact $contact): FlowSession
    {
        $definition = $this->createDefinition([
            ['id' => 'p-sub', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => 'orphan', 'timeout' => 'PT1H']],
        ], []);

        return $this->createSession($contact, [
            'flow_definition_id' => $definition->getKey(),
            'current_node_id'    => 'p-sub',
            'status'             => FlowSessionStatus::PausedSubflow,
            'expires_at'         => Carbon::now()->subMinutes(5),
        ]);
    }

    private function newContact(): Contact
    {
        return Contact::factory()->forTenant($this->tenantId)->create();
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
            'name'      => 'sweeper lock test flow',
            'nodes'     => $nodes,
            'edges'     => $edges,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSession(Contact $contact, array $overrides): FlowSession
    {
        return FlowSession::query()->create(array_merge([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'contact_id'   => $contact->getKey(),
            'flow_version' => 1,
            'state'        => [],
            'version'      => 1,
        ], $overrides));
    }
}
