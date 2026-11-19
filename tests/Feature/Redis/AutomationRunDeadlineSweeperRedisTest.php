<?php

declare(strict_types=1);

namespace Tests\Feature\Redis;

use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Concurrency\SessionLockManager;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Orchestration\AutomationRunDeadlineSweeper;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\FeatureTestCase;

/**
 * The deadline sweeper fails a run only under its trigger's lock — the one the run's segments hold while they run —
 * and skips a run whose lock another worker holds.
 */
#[Group('redis')]
final class AutomationRunDeadlineSweeperRedisTest extends FeatureTestCase
{
    use InteractsWithRedisLocks;

    private string $tenantId;

    private FlowDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the busy-lock case fast: the default budget is 3 * 2s.
        config([
            'flow.lock.acquisition_retries' => 1,
            'flow.lock.retry_delay_ms'      => 50,
        ]);

        $this->tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: $this->tenantId, schemaName: 'main'));
        $this->definition = FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Deadline lock',
            'nodes'     => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'     => [],
            'is_active' => true,
        ]);
    }

    public function test_a_run_whose_trigger_lock_is_held_is_skipped_and_the_others_are_still_swept(): void
    {
        $busy = $this->overdueRun();
        $free = $this->overdueRun();

        $scope = LockScope::ofSession($busy);
        $this->trackLockKey($scope->key());
        $this->trackLockKey(LockScope::ofSession($free)->key());
        $holder = $this->app->make(SessionLockManager::class)->acquire($scope, ttlSeconds: 30);
        $this->assertNotNull($holder, 'Precondition: a segment of the run holds its lock.');

        $report = $this->app->make(AutomationRunDeadlineSweeper::class)->sweep($this->tenantId);

        $this->assertSame(['failed_runs' => 1, 'failed_sessions' => 1, 'skipped_busy' => 1], $report);
        $this->assertSame(FlowSessionStatus::Active, $busy->refresh()->status);
        $this->assertSame(FlowSessionStatus::Failed, $free->refresh()->status);
        $this->assertSame($holder->token, $this->redisGet($holder->key), 'The holder keeps its lock.');
        $this->assertNull($this->redisGet(LockScope::ofSession($free)->key()), 'The sweeper releases the lock it took.');
    }

    private function overdueRun(): FlowSession
    {
        return FlowSession::query()->create([
            'tenant_id'          => $this->tenantId,
            'flow_definition_id' => $this->definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'end',
            'status'             => FlowSessionStatus::Active,
            'run_kind'           => FlowRunKind::Automation,
            'trigger_id'         => (string) Str::uuid(),
            'deadline_at'        => now()->subMinute(),
            'state'              => [],
            'version'            => 1,
        ]);
    }
}
