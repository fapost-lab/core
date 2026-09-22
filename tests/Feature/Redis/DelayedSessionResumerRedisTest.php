<?php

declare(strict_types=1);

namespace Tests\Feature\Redis;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Concurrency\SessionLockManager;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Orchestration\DelayedSessionResumer;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\FeatureTestCase;

/**
 * {@see DelayedSessionResumer::resume()} (the queued-job side) against a real
 * Redis lock. Covers both forms it accepts: the `delay` node's
 * `waiting_input` + `system.delay.*` marker, and `delayed(resumeAt: ...)`'s
 * `paused` + `system.delayed.*` marker — the latter must run the engine with
 * {@code resumedAfterDelay: true}. Only the engine is mocked (a spy verifying
 * it is or isn't called); the lock itself is never mocked.
 */
#[Group('redis')]
final class DelayedSessionResumerRedisTest extends FeatureTestCase
{
    use InteractsWithRedisLocks;

    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pingRedisOrFail();

        Queue::fake();

        // Keep the busy-lock case fast: the default budget is 3 * 2s.
        config([
            'flow.lock.acquisition_retries' => 1,
            'flow.lock.retry_delay_ms'      => 50,
        ]);
    }

    public function test_busy_lock_throws_and_the_engine_is_never_called(): void
    {
        [$session, $scope] = $this->makeSession(FlowSessionStatus::Paused, [
            'system' => ['delayed' => ['d1' => ['resume_at' => Carbon::now()->toAtomString()]]],
        ]);

        $manager = $this->app->make(SessionLockManager::class);
        $holder  = $manager->acquire($scope, ttlSeconds: 30);
        $this->assertNotNull($holder, 'Precondition: another worker must hold the session lock.');

        $engine = $this->createMock(FlowEngineInterface::class);
        $engine->expects($this->never())->method('runSession');
        $this->app->instance(FlowEngineInterface::class, $engine);

        $resumer = $this->app->make(DelayedSessionResumer::class);

        $this->expectException(SessionLockTimeoutException::class);

        try {
            $resumer->resume((string) $session->getKey(), 'd1', Carbon::now());
        } finally {
            $this->assertSame(
                $holder->token,
                $this->redisGet($scope->key()),
                'The busy holder\'s claim must be untouched by the failed acquisition attempt.',
            );
        }
    }

    public function test_delay_node_form_wakes_without_the_resumed_after_delay_flag(): void
    {
        // Round-tripped through ATOM, matching how the persisted marker and the
        // job's own resumeAt are both reconstructed in production (see
        // ResumeDelayedFlowSessionJob) — a raw Carbon still carrying
        // microseconds would never equalTo() the marker read back from JSON.
        $resumeAt          = $this->normalizedResumeAt(Carbon::now()->subSecond());
        [$session, $scope] = $this->makeSession(FlowSessionStatus::WaitingInput, [
            'system' => ['delay' => ['d1' => ['resume_at' => $resumeAt->toAtomString()]]],
        ]);

        $engine = $this->createMock(FlowEngineInterface::class);
        $engine->expects($this->once())
            ->method('runSession')
            ->with(
                $this->callback(static fn (FlowSession $arg): bool => $arg->getKey() === $session->getKey()),
                false,
            )
            ->willReturn($session);
        $this->app->instance(FlowEngineInterface::class, $engine);

        $resumer = $this->app->make(DelayedSessionResumer::class);
        $resumer->resume((string) $session->getKey(), 'd1', $resumeAt);

        $this->assertNull($this->redisGet($scope->key()), 'The lock must be gone once the run completes.');
    }

    public function test_paused_form_wakes_with_the_resumed_after_delay_flag(): void
    {
        $resumeAt          = $this->normalizedResumeAt(Carbon::now()->subSecond());
        [$session, $scope] = $this->makeSession(FlowSessionStatus::Paused, [
            'system' => ['delayed' => ['d1' => ['resume_at' => $resumeAt->toAtomString()]]],
        ]);

        $engine = $this->createMock(FlowEngineInterface::class);
        $engine->expects($this->once())
            ->method('runSession')
            ->with(
                $this->callback(static fn (FlowSession $arg): bool => $arg->getKey() === $session->getKey()),
                true,
            )
            ->willReturn($session);
        $this->app->instance(FlowEngineInterface::class, $engine);

        $resumer = $this->app->make(DelayedSessionResumer::class);
        $resumer->resume((string) $session->getKey(), 'd1', $resumeAt);

        $this->assertNull($this->redisGet($scope->key()), 'The lock must be gone once the run completes.');
    }

    public function test_mismatched_marker_is_a_no_op(): void
    {
        [$session, $scope] = $this->makeSession(FlowSessionStatus::Paused, [
            'system' => ['delayed' => ['d1' => ['resume_at' => Carbon::now()->subMinute()->toAtomString()]]],
        ]);

        $engine = $this->createMock(FlowEngineInterface::class);
        $engine->expects($this->never())->method('runSession');
        $this->app->instance(FlowEngineInterface::class, $engine);

        $resumer = $this->app->make(DelayedSessionResumer::class);
        // A different resume_at than the one stored — stale/duplicate resume.
        $resumer->resume((string) $session->getKey(), 'd1', Carbon::now()->subSeconds(30));

        $this->assertNull($this->redisGet($scope->key()), 'The lock must still be released even on a no-op.');
    }

    /**
     * Strips sub-second precision the same way `->format(DateTimeInterface::ATOM)`
     * does when the marker is written to `state` and re-read from it.
     */
    private function normalizedResumeAt(Carbon $resumeAt): Carbon
    {
        return Carbon::parse($resumeAt->format(DateTimeInterface::ATOM));
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{0: FlowSession, 1: LockScope}
     */
    private function makeSession(FlowSessionStatus $status, array $state): array
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();

        $definition = FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Resumer Redis Test',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        $session = FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'd1',
            'state'              => $state,
            'status'             => $status,
            'version'            => 1,
        ]);

        $scope = new LockScope(self::TENANT_ID, (string) $contact->getKey(), (string) $assistant->getKey());
        $this->trackLockKey($scope->key());

        return [$session, $scope];
    }
}
