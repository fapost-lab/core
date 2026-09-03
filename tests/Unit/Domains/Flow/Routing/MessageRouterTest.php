<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Routing;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Contracts\ConversationOwnershipInterface;
use App\Domains\Flow\Commands\BuiltinCommandsRegistry;
use App\Domains\Flow\Commands\CommandMatcher;
use App\Domains\Flow\Commands\GlobalCommandExecutorInterface;
use App\Domains\Flow\Concurrency\LockAcquisitionPolicy;
use App\Domains\Flow\Concurrency\SessionLockManager;
use App\Domains\Flow\Concurrency\SessionLockRegistry;
use App\Domains\Flow\Contracts\FlowOrchestratorInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\SessionLockLostException;
use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Routing\DropPolicyInterface;
use App\Domains\Flow\Routing\MessageRouter;
use App\Domains\Flow\Routing\SessionStateRouter;
use App\Domains\Messaging\Typing\TypingHeartbeatRegistry;
use App\Domains\Messaging\Typing\TypingIndicatorService;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\Flow\Contracts\TriggerResolverInterface;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class MessageRouterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_command_match_short_circuits_pipeline_before_lock_acquisition(): void
    {
        $executor = Mockery::mock(GlobalCommandExecutorInterface::class);
        $executor->shouldReceive('execute')->once();

        $router = $this->router(
            commandExecutor: $executor,
            lockPolicy: $this->neverAcquiresLock(),
        );

        $outcome = $router->route(
            contact: $this->contact(),
            message: $this->message('/reset'),
            assistant: $this->assistant(),
            channel: $this->channel(),
        );

        $this->assertSame('command', $outcome->kind);
        $this->assertSame('/reset', $outcome->command);
    }

    public function test_lock_busy_after_retries_returns_dropped_with_lock_timeout_reason(): void
    {
        [$lockPolicy] = $this->acquiringLock(false);

        $dropPolicy = Mockery::mock(DropPolicyInterface::class);
        $dropPolicy->shouldReceive('applyBusy')->once();

        $router = $this->router(lockPolicy: $lockPolicy, dropPolicy: $dropPolicy);

        $outcome = $router->route(
            contact: $this->contact(),
            message: $this->message('hello'),
            assistant: $this->assistant(),
            channel: $this->channel(),
        );

        $this->assertTrue($outcome->wasDropped());
        $this->assertSame('lock_timeout', $outcome->reason);
    }

    public function test_waiting_input_session_resumes_via_orchestrator_without_trigger(): void
    {
        $session  = $this->makeSession(FlowSessionStatus::WaitingInput);
        $sessions = Mockery::mock(FlowSessionRepositoryInterface::class);
        $sessions->shouldReceive('findActiveForContact')->once()->andReturn($session);

        $orchestrator = Mockery::mock(FlowOrchestratorInterface::class);
        $orchestrator->shouldReceive('handle')
            ->once()
            ->withArgs(static fn ($contact, $msg, $assistantId, $trigger): bool => null === $trigger);

        $triggerResolver = Mockery::mock(TriggerResolverInterface::class);
        $triggerResolver->shouldNotReceive('resolve');

        $router = $this->router(
            sessions: $sessions,
            orchestrator: $orchestrator,
            triggerResolver: $triggerResolver,
        );

        $outcome = $router->route(
            contact: $this->contact(),
            message: $this->message('hello'),
            assistant: $this->assistant(),
            channel: $this->channel(),
        );

        $this->assertSame('executed', $outcome->kind);
    }

    public function test_paused_session_drops_with_busy_notice(): void
    {
        $session  = $this->makeSession(FlowSessionStatus::Paused);
        $sessions = Mockery::mock(FlowSessionRepositoryInterface::class);
        $sessions->shouldReceive('findActiveForContact')->once()->andReturn($session);

        $dropPolicy = Mockery::mock(DropPolicyInterface::class);
        $dropPolicy->shouldReceive('applyBusy')->once();

        $orchestrator = Mockery::mock(FlowOrchestratorInterface::class);
        $orchestrator->shouldNotReceive('handle');

        $router = $this->router(
            sessions: $sessions,
            dropPolicy: $dropPolicy,
            orchestrator: $orchestrator,
        );

        $outcome = $router->route(
            contact: $this->contact(),
            message: $this->message('hello'),
            assistant: $this->assistant(),
            channel: $this->channel(),
        );

        $this->assertTrue($outcome->wasDropped());
        $this->assertSame('drop_busy', $outcome->reason);
    }

    public function test_no_active_session_resolves_trigger_and_starts_via_orchestrator(): void
    {
        $sessions = Mockery::mock(FlowSessionRepositoryInterface::class);
        $sessions->shouldReceive('findActiveForContact')->once()->andReturn(null);

        $triggerResolver = Mockery::mock(TriggerResolverInterface::class);
        $triggerResolver->shouldReceive('resolve')->once()->andReturn(null);

        $orchestrator = Mockery::mock(FlowOrchestratorInterface::class);
        $orchestrator->shouldReceive('handle')->once();

        $router = $this->router(
            sessions: $sessions,
            triggerResolver: $triggerResolver,
            orchestrator: $orchestrator,
        );

        $outcome = $router->route(
            contact: $this->contact(),
            message: $this->message('hello'),
            assistant: $this->assistant(),
            channel: $this->channel(),
        );

        $this->assertSame('executed', $outcome->kind);
    }

    public function test_engine_lock_timeout_from_orchestrator_drops_with_engine_lock_timeout_reason(): void
    {
        $session  = $this->makeSession(FlowSessionStatus::WaitingInput);
        $sessions = Mockery::mock(FlowSessionRepositoryInterface::class);
        $sessions->shouldReceive('findActiveForContact')->once()->andReturn($session);

        // The engine's own guard fails to acquire its lock — the router must
        // convert that into an engine_lock_timeout drop rather than surface it.
        $orchestrator = Mockery::mock(FlowOrchestratorInterface::class);
        $orchestrator->shouldReceive('handle')
            ->once()
            ->andThrow(new SessionLockTimeoutException('session_lock:tenant-1:contact-1:assistant-1'));

        $router = $this->router(sessions: $sessions, orchestrator: $orchestrator);

        $outcome = $router->route(
            contact: $this->contact(),
            message: $this->message('hello'),
            assistant: $this->assistant(),
            channel: $this->channel(),
        );

        $this->assertTrue($outcome->wasDropped());
        $this->assertSame('engine_lock_timeout', $outcome->reason);
    }

    public function test_session_lock_lost_from_orchestrator_drops_with_lock_lost_reason(): void
    {
        $session  = $this->makeSession(FlowSessionStatus::WaitingInput);
        $sessions = Mockery::mock(FlowSessionRepositoryInterface::class);
        $sessions->shouldReceive('findActiveForContact')->once()->andReturn($session);

        // The engine's heartbeat found the claim taken over mid-execution and
        // abandoned the run — the router must convert that into a lock_lost
        // drop rather than let it bubble up as an unhandled exception.
        $orchestrator = Mockery::mock(FlowOrchestratorInterface::class);
        $orchestrator->shouldReceive('handle')
            ->once()
            ->andThrow(new SessionLockLostException('session_lock:tenant-1:contact-1:assistant-1'));

        $router = $this->router(sessions: $sessions, orchestrator: $orchestrator);

        $outcome = $router->route(
            contact: $this->contact(),
            message: $this->message('hello'),
            assistant: $this->assistant(),
            channel: $this->channel(),
        );

        $this->assertTrue($outcome->wasDropped());
        $this->assertSame('lock_lost', $outcome->reason);
    }

    public function test_staff_handled_thread_drops_before_session_classification_and_never_calls_orchestrator(): void
    {
        $ownership = Mockery::mock(ConversationOwnershipInterface::class);
        $ownership->shouldReceive('isHandledByStaff')->once()->andReturn(true);

        // Session repository must never even be consulted: the whole point of
        // step 4a running before 4b is that a stale waiting_input session left
        // over from before the takeover must not resume the flow.
        $sessions = Mockery::mock(FlowSessionRepositoryInterface::class);
        $sessions->shouldNotReceive('findActiveForContact');

        $orchestrator = Mockery::mock(FlowOrchestratorInterface::class);
        $orchestrator->shouldNotReceive('handle');

        $dropPolicy = Mockery::mock(DropPolicyInterface::class);
        $dropPolicy->shouldNotReceive('applyBusy');
        $dropPolicy->shouldNotReceive('applySilent');

        $router = $this->router(
            ownership: $ownership,
            sessions: $sessions,
            orchestrator: $orchestrator,
            dropPolicy: $dropPolicy,
        );

        $outcome = $router->route(
            contact: $this->contact(),
            message: $this->message('hello'),
            assistant: $this->assistant(),
            channel: $this->channel(),
        );

        $this->assertTrue($outcome->wasDropped());
        $this->assertSame('staff_handled', $outcome->reason);
    }

    public function test_staff_handled_thread_still_releases_the_session_lock(): void
    {
        $calls                      = [];
        [$lockPolicy, $lockManager] = $this->acquiringLockWithCallLog(true, $calls);

        $ownership = Mockery::mock(ConversationOwnershipInterface::class);
        $ownership->shouldReceive('isHandledByStaff')->once()->andReturn(true);

        $router = $this->router(
            ownership: $ownership,
            lockPolicy: $lockPolicy,
            lockManager: $lockManager,
        );

        $router->route(
            contact: $this->contact(),
            message: $this->message('hello'),
            assistant: $this->assistant(),
            channel: $this->channel(),
        );

        // 'set' is the acquire (SET NX EX), 'eval' is the token-checked release
        // script — both must have run even though the pipeline short-circuited
        // at step 4a, otherwise the lock would leak until its TTL expires.
        $this->assertContains('set', $calls);
        $this->assertContains('eval', $calls);
    }

    public function test_global_command_short_circuits_before_ownership_check_even_when_staff_holds_the_thread(): void
    {
        $executor = Mockery::mock(GlobalCommandExecutorInterface::class);
        $executor->shouldReceive('execute')->once();

        // /reset must work even in a thread an operator holds — ownership is
        // never even consulted because step 1 returns first.
        $ownership = Mockery::mock(ConversationOwnershipInterface::class);
        $ownership->shouldNotReceive('isHandledByStaff');

        $router = $this->router(
            commandExecutor: $executor,
            ownership: $ownership,
            lockPolicy: $this->neverAcquiresLock(),
        );

        $outcome = $router->route(
            contact: $this->contact(),
            message: $this->message('/reset'),
            assistant: $this->assistant(),
            channel: $this->channel(),
        );

        $this->assertSame('command', $outcome->kind);
        $this->assertSame('/reset', $outcome->command);
    }

    public function test_not_handled_by_staff_falls_through_to_session_classification_as_before(): void
    {
        $ownership = Mockery::mock(ConversationOwnershipInterface::class);
        $ownership->shouldReceive('isHandledByStaff')->once()->andReturn(false);

        $sessions = Mockery::mock(FlowSessionRepositoryInterface::class);
        $sessions->shouldReceive('findActiveForContact')->once()->andReturn(null);

        $triggerResolver = Mockery::mock(TriggerResolverInterface::class);
        $triggerResolver->shouldReceive('resolve')->once()->andReturn(null);

        $orchestrator = Mockery::mock(FlowOrchestratorInterface::class);
        $orchestrator->shouldReceive('handle')->once();

        $router = $this->router(
            ownership: $ownership,
            sessions: $sessions,
            triggerResolver: $triggerResolver,
            orchestrator: $orchestrator,
        );

        $outcome = $router->route(
            contact: $this->contact(),
            message: $this->message('hello'),
            assistant: $this->assistant(),
            channel: $this->channel(),
        );

        $this->assertSame('executed', $outcome->kind);
    }

    private function router(
        ?GlobalCommandExecutorInterface $commandExecutor = null,
        ?TypingIndicatorService $typing = null,
        ?SessionStateRouter $stateRouter = null,
        ?DropPolicyInterface $dropPolicy = null,
        ?FlowSessionRepositoryInterface $sessions = null,
        ?TriggerResolverInterface $triggerResolver = null,
        ?FlowOrchestratorInterface $orchestrator = null,
        ?LockAcquisitionPolicy $lockPolicy = null,
        ?SessionLockManager $lockManager = null,
        ?SessionLockRegistry $lockRegistry = null,
        ?ConversationOwnershipInterface $ownership = null,
    ): MessageRouter {
        // Use real CommandMatcher (final class). Built-ins include /reset and
        // /cancel — assistant.commands stays empty so no tenant overrides apply.
        $matcher = new CommandMatcher(new BuiltinCommandsRegistry());

        [$defaultPolicy, $defaultManager] = $this->acquiringLock(true);

        return new MessageRouter(
            commandMatcher: $matcher,
            commandExecutor: $commandExecutor ?? Mockery::mock(GlobalCommandExecutorInterface::class),
            typing: $typing ?? $this->noOpTyping(),
            stateRouter: $stateRouter ?? new SessionStateRouter(),
            dropPolicy: $dropPolicy ?? Mockery::mock(DropPolicyInterface::class)->shouldIgnoreMissing(),
            sessions: $sessions ?? Mockery::mock(FlowSessionRepositoryInterface::class)->shouldIgnoreMissing(),
            triggerResolver: $triggerResolver ?? Mockery::mock(TriggerResolverInterface::class)->shouldIgnoreMissing(),
            orchestrator: $orchestrator ?? Mockery::mock(FlowOrchestratorInterface::class)->shouldIgnoreMissing(),
            lockPolicy: $lockPolicy ?? $defaultPolicy,
            lockManager: $lockManager ?? $defaultManager,
            lockRegistry: $lockRegistry ?? new SessionLockRegistry(),
            ownership: $ownership ?? $this->notHandledByStaff(),
            typingHeartbeat: new TypingHeartbeatRegistry(),
        );
    }

    /**
     * Default ownership stub for tests that don't exercise takeover — no
     * operator holds the thread, so the pipeline behaves exactly as it did
     * before {@see ConversationOwnershipInterface} existed.
     */
    private function notHandledByStaff(): ConversationOwnershipInterface
    {
        $ownership = Mockery::mock(ConversationOwnershipInterface::class);
        $ownership->shouldReceive('isHandledByStaff')->andReturn(false);

        return $ownership;
    }

    private function noOpTyping(): TypingIndicatorService
    {
        // Real service over a registry that returns null sender — start()
        // short-circuits and returns null, the no-op behaviour we want.
        $registry = Mockery::mock(ChannelRegistryInterface::class);
        $registry->shouldReceive('sender')->andReturn(null);

        return new TypingIndicatorService($registry);
    }

    /**
     * Build a policy backed by a real {@see SessionLockManager} whose fake
     * Redis connection reports the given acquisition outcome for every `set
     * NX EX` call and always succeeds on the token-checked release script.
     *
     * @return array{0: LockAcquisitionPolicy, 1: SessionLockManager}
     */
    private function acquiringLock(bool $acquired): array
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('__call')
            ->willReturnCallback(static fn (string $method): mixed => match ($method) {
                'set'   => $acquired,
                'eval'  => 1,
                default => null,
            });

        $manager = new SessionLockManager($this->factoryFor($connection));

        return [new LockAcquisitionPolicy($manager, retryDelayMs: 0), $manager];
    }

    /**
     * Same as {@see acquiringLock()}, but records every Redis command name
     * invoked on the fake connection into `$calls` (by reference) so a test
     * can assert both acquire ('set') and release ('eval') actually ran.
     *
     * @return array{0: LockAcquisitionPolicy, 1: SessionLockManager}
     */
    private function acquiringLockWithCallLog(bool $acquired, array &$calls): array
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('__call')
            ->willReturnCallback(static function (string $method) use ($acquired, &$calls): mixed {
                $calls[] = $method;

                return match ($method) {
                    'set'   => $acquired,
                    'eval'  => 1,
                    default => null,
                };
            });

        $manager = new SessionLockManager($this->factoryFor($connection));

        return [new LockAcquisitionPolicy($manager, retryDelayMs: 0), $manager];
    }

    /**
     * Policy whose connection asserts it is never touched — used for the
     * pre-lock short-circuit case (global command match).
     */
    private function neverAcquiresLock(): LockAcquisitionPolicy
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('__call');

        return new LockAcquisitionPolicy(new SessionLockManager($this->factoryFor($connection)), retryDelayMs: 0);
    }

    private function factoryFor(MockObject&Connection $connection): RedisFactory
    {
        return new class ($connection) implements RedisFactory {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function connection($name = null): Connection
            {
                return $this->connection;
            }
        };
    }

    private function contact(): Contact
    {
        return Contact::factory()->make([
            'id'        => 'contact-1',
            'tenant_id' => 'tenant-1',
        ]);
    }

    private function assistant(): Assistant
    {
        $assistant = Assistant::make([
            'name'             => 'Test',
            'is_active'        => true,
            'default_language' => 'en',
            'fallback_message' => null,
        ]);
        $assistant->id     = 'assistant-1';
        $assistant->exists = true;

        return $assistant;
    }

    private function channel(): Channel
    {
        $channel = Channel::make([
            'type'      => ChannelTypeEnum::Telegram,
            'token'     => 'token-1',
            'is_active' => true,
        ]);
        $channel->id     = 'channel-1';
        $channel->exists = true;

        return $channel;
    }

    private function message(string $text): IncomingMessage
    {
        return new IncomingMessage(
            updateId: 'upd-1',
            externalUserId: 'ext-user',
            externalChatId: 'ext-chat',
            text: $text,
            type: IncomingMessageType::Text,
            platform: 'telegram',
        );
    }

    private function makeSession(FlowSessionStatus $status): FlowSession
    {
        $session = FlowSession::make([
            'tenant_id'    => 'tenant-1',
            'assistant_id' => 'assistant-1',
            'contact_id'   => 'contact-1',
            'status'       => $status,
            'version'      => 1,
        ]);
        $session->id = 'session-1';

        return $session;
    }
}
