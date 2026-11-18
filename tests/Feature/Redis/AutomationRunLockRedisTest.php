<?php

declare(strict_types=1);

namespace Tests\Feature\Redis;

use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Concurrency\SessionLockManager;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Jobs\Flow\ContinueAutomationRunJob;
use App\Jobs\Flow\ResumeDelayedFlowSessionJob;
use App\Jobs\Flow\StartAutomationRunJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\FeatureTestCase;

/**
 * Contactless runs of one trigger are serialized on the (tenant, trigger) lock. A start or a continuation that finds
 * it busy goes back to the queue instead of spending an attempt; another trigger's lock does not hold it up.
 */
#[Group('redis')]
final class AutomationRunLockRedisTest extends FeatureTestCase
{
    use InteractsWithRedisLocks;

    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pingRedisOrFail();

        Queue::fake();
        config([
            'flow.lock.acquisition_retries' => 1,
            'flow.lock.retry_delay_ms'      => 50,
        ]);
    }

    public function test_a_start_that_finds_its_trigger_busy_is_released_and_starts_nothing(): void
    {
        $triggerId = (string) Str::uuid();
        $this->holdTriggerLock($triggerId);

        $job = (new StartAutomationRunJob(self::TENANT_ID, $this->endOnlyFlow(), $triggerId, 'slot-1'))
            ->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);

        $job->assertReleased(StartAutomationRunJob::LOCK_BUSY_RELEASE_SECONDS);
        $this->assertSame(0, FlowSession::query()->count());
    }

    public function test_a_continuation_that_finds_its_trigger_busy_is_released_and_runs_nothing(): void
    {
        $triggerId = (string) Str::uuid();
        $flowId    = $this->endOnlyFlow();
        $session   = FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'flow_definition_id' => FlowDefinition::query()->where('flow_id', $flowId)->sole()->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'end',
            'state'              => [],
            'status'             => FlowSessionStatus::Active,
            'run_kind'           => FlowRunKind::Automation,
            'trigger_id'         => $triggerId,
            'segment_id'         => 'a0000000-0000-0000-0000-000000000001',
            'version'            => 1,
        ]);
        $this->holdTriggerLock($triggerId);

        $job = (new ContinueAutomationRunJob(self::TENANT_ID, (string) $session->getKey(), 'a0000000-0000-0000-0000-000000000001'))
            ->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);

        $job->assertReleased(ContinueAutomationRunJob::LOCK_BUSY_RELEASE_SECONDS);
        $this->assertSame(FlowSessionStatus::Active, $session->refresh()->status);
    }

    public function test_another_trigger_of_the_same_flow_runs_while_one_is_held(): void
    {
        $flowId = $this->endOnlyFlow();
        $this->holdTriggerLock((string) Str::uuid());
        $otherTrigger = (string) Str::uuid();
        $this->trackLockKey(LockScope::forTrigger(self::TENANT_ID, $otherTrigger)->key());

        $job = (new StartAutomationRunJob(self::TENANT_ID, $flowId, $otherTrigger, 'slot-1'))->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);

        $job->assertNotReleased();
        $this->assertSame(FlowSessionStatus::Ended, FlowSession::query()->sole()->status);
        $this->assertNull($this->redisGet(LockScope::forTrigger(self::TENANT_ID, $otherTrigger)->key()));
    }

    public function test_a_delayed_automation_that_finds_its_trigger_busy_is_released_and_stays_parked(): void
    {
        $triggerId = (string) Str::uuid();
        $flowId    = $this->endOnlyFlow();
        $resumeAt  = now()->subSecond()->toAtomString();
        $session   = FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'flow_definition_id' => FlowDefinition::query()->where('flow_id', $flowId)->sole()->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'end',
            'state'              => ['system' => ['delay' => ['end' => ['resume_at' => $resumeAt]]]],
            'status'             => FlowSessionStatus::WaitingInput,
            'run_kind'           => FlowRunKind::Automation,
            'trigger_id'         => $triggerId,
            'version'            => 1,
        ]);
        $this->holdTriggerLock($triggerId);

        $job = (new ResumeDelayedFlowSessionJob(self::TENANT_ID, (string) $session->getKey(), 'end', $resumeAt))
            ->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);

        $job->assertReleased(ResumeDelayedFlowSessionJob::LOCK_BUSY_RELEASE_SECONDS);
        $this->assertSame(FlowSessionStatus::WaitingInput, $session->refresh()->status);
    }

    private function holdTriggerLock(string $triggerId): void
    {
        $scope = LockScope::forTrigger(self::TENANT_ID, $triggerId);
        $this->trackLockKey($scope->key());

        $this->assertNotNull(
            $this->app->make(SessionLockManager::class)->acquire($scope, ttlSeconds: 30),
            'Precondition: another run of the trigger holds its lock.',
        );
    }

    private function endOnlyFlow(): string
    {
        $flowId = (string) Str::uuid();

        FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => $flowId,
            'version'   => 1,
            'name'      => 'Automation lock',
            'nodes'     => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'     => [],
            'is_active' => true,
        ]);

        return $flowId;
    }
}
