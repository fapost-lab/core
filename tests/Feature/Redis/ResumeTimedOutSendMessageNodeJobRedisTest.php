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
use App\Jobs\Flow\ResumeTimedOutSendMessageNodeJob;
use Fapost\Foundation\DTO\IncomingMessage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\FeatureTestCase;

/**
 * {@see ResumeTimedOutSendMessageNodeJob} now runs its re-read of the session
 * under {@see \App\Infrastructure\Flow\FlowExecutionGuard}, backed here by a
 * real Redis lock. Only the engine is mocked (a spy verifying it is or isn't
 * called) — the lock itself is never mocked.
 */
#[Group('redis')]
final class ResumeTimedOutSendMessageNodeJobRedisTest extends FeatureTestCase
{
    use InteractsWithRedisLocks;

    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pingRedisOrFail();

        // Keep the busy-lock case fast: the default budget is 3 * 2s.
        config([
            'flow.lock.acquisition_retries' => 1,
            'flow.lock.retry_delay_ms'      => 50,
        ]);
    }

    public function test_busy_lock_throws_and_the_engine_is_never_called(): void
    {
        [$session, $scope] = $this->makeSessionOnNode('send1');

        $manager = $this->app->make(SessionLockManager::class);
        $holder  = $manager->acquire($scope, ttlSeconds: 30);
        $this->assertNotNull($holder, 'Precondition: another worker must hold the session lock.');

        $engine = $this->createMock(FlowEngineInterface::class);
        $engine->expects($this->never())->method('resume');
        $this->app->instance(FlowEngineInterface::class, $engine);

        $job = new ResumeTimedOutSendMessageNodeJob(
            tenantId: self::TENANT_ID,
            sessionId: (string) $session->getKey(),
            nodeId: 'send1',
            platform: 'telegram',
        );

        $this->expectException(SessionLockTimeoutException::class);

        try {
            app()->call([$job, 'handle']);
        } finally {
            $this->assertSame(
                $holder->token,
                $this->redisGet($scope->key()),
                'The busy holder\'s claim must be untouched by the failed acquisition attempt.',
            );
        }
    }

    public function test_session_no_longer_on_the_timed_out_node_skips_the_engine(): void
    {
        [$session, $scope] = $this->makeSessionOnNode('other-node');

        $engine = $this->createMock(FlowEngineInterface::class);
        $engine->expects($this->never())->method('resume');
        $this->app->instance(FlowEngineInterface::class, $engine);

        $job = new ResumeTimedOutSendMessageNodeJob(
            tenantId: self::TENANT_ID,
            sessionId: (string) $session->getKey(),
            nodeId: 'send1',
            platform: 'telegram',
        );

        app()->call([$job, 'handle']);

        $this->assertNull($this->redisGet($scope->key()), 'The lock must be released once the guarded callback returns.');
    }

    public function test_happy_path_calls_engine_resume_once_and_releases_the_lock(): void
    {
        [$session, $scope] = $this->makeSessionOnNode('send1');

        $engine = $this->createMock(FlowEngineInterface::class);
        $engine->expects($this->once())
            ->method('resume')
            ->with(
                $this->callback(static fn (FlowSession $arg): bool => $arg->getKey() === $session->getKey()),
                $this->isInstanceOf(IncomingMessage::class),
            )
            ->willReturn($session);
        $this->app->instance(FlowEngineInterface::class, $engine);

        $job = new ResumeTimedOutSendMessageNodeJob(
            tenantId: self::TENANT_ID,
            sessionId: (string) $session->getKey(),
            nodeId: 'send1',
            platform: 'telegram',
        );

        app()->call([$job, 'handle']);

        $this->assertNull($this->redisGet($scope->key()), 'The lock must be gone once the happy-path run completes.');
    }

    /**
     * @return array{0: FlowSession, 1: LockScope}
     */
    private function makeSessionOnNode(string $currentNodeId): array
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();

        $definition = FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Resume Lock Test',
            'nodes'     => [
                ['id' => 'send1', 'type' => 'send_message', 'version' => 1, 'config' => []],
            ],
            'edges'     => [],
            'is_active' => true,
        ]);

        $session = FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => $currentNodeId,
            'state'              => [],
            'status'             => FlowSessionStatus::Active,
            'version'            => 0,
        ]);

        $scope = new LockScope(self::TENANT_ID, (string) $contact->getKey(), (string) $assistant->getKey());
        $this->trackLockKey($scope->key());

        return [$session, $scope];
    }
}
