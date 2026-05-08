<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Routing;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Commands\BuiltinCommandsRegistry;
use App\Domains\Flow\Commands\CommandMatcher;
use App\Domains\Flow\Commands\GlobalCommandExecutorInterface;
use App\Domains\Flow\Contracts\FlowOrchestratorInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Routing\DropPolicyInterface;
use App\Domains\Flow\Routing\MessageRouter;
use App\Domains\Flow\Routing\SessionStateRouter;
use App\Domains\Messaging\Typing\TypingHeartbeatRegistry;
use App\Domains\Messaging\Typing\TypingIndicatorService;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\IncomingMessageType;
use FAPost\Foundation\Flow\Contracts\TriggerResolverInterface;
use Illuminate\Cache\CacheLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Mockery;
use Mockery\MockInterface;
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

        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldNotReceive('lock');

        $router = $this->router(
            commandExecutor: $executor,
            cache: $cache,
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
        $cache = Mockery::mock(CacheRepository::class);
        $lock  = $this->lockMock(false);
        $cache->shouldReceive('lock')->once()->andReturn($lock);

        $dropPolicy = Mockery::mock(DropPolicyInterface::class);
        $dropPolicy->shouldReceive('applyBusy')->once();

        $router = $this->router(cache: $cache, dropPolicy: $dropPolicy);

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
        $cache = Mockery::mock(CacheRepository::class);
        $lock  = $this->lockMock(true);
        $cache->shouldReceive('lock')->once()->andReturn($lock);

        $session = $this->makeSession(FlowSessionStatus::WaitingInput);
        $sessions = Mockery::mock(FlowSessionRepositoryInterface::class);
        $sessions->shouldReceive('findActiveForContact')->once()->andReturn($session);

        $orchestrator = Mockery::mock(FlowOrchestratorInterface::class);
        $orchestrator->shouldReceive('handle')
            ->once()
            ->withArgs(static fn ($contact, $msg, $assistantId, $trigger): bool => null === $trigger);

        $triggerResolver = Mockery::mock(TriggerResolverInterface::class);
        $triggerResolver->shouldNotReceive('resolve');

        $router = $this->router(
            cache: $cache,
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
        $cache = Mockery::mock(CacheRepository::class);
        $lock  = $this->lockMock(true);
        $cache->shouldReceive('lock')->once()->andReturn($lock);

        $session = $this->makeSession(FlowSessionStatus::Paused);
        $sessions = Mockery::mock(FlowSessionRepositoryInterface::class);
        $sessions->shouldReceive('findActiveForContact')->once()->andReturn($session);

        $dropPolicy = Mockery::mock(DropPolicyInterface::class);
        $dropPolicy->shouldReceive('applyBusy')->once();

        $orchestrator = Mockery::mock(FlowOrchestratorInterface::class);
        $orchestrator->shouldNotReceive('handle');

        $router = $this->router(
            cache: $cache,
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
        $cache = Mockery::mock(CacheRepository::class);
        $lock  = $this->lockMock(true);
        $cache->shouldReceive('lock')->once()->andReturn($lock);

        $sessions = Mockery::mock(FlowSessionRepositoryInterface::class);
        $sessions->shouldReceive('findActiveForContact')->once()->andReturn(null);

        $triggerResolver = Mockery::mock(TriggerResolverInterface::class);
        $triggerResolver->shouldReceive('resolve')->once()->andReturn(null);

        $orchestrator = Mockery::mock(FlowOrchestratorInterface::class);
        $orchestrator->shouldReceive('handle')->once();

        $router = $this->router(
            cache: $cache,
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
        ?CacheRepository $cache = null,
    ): MessageRouter {
        // Use real CommandMatcher (final class). Built-ins include /reset and
        // /cancel — assistant.commands stays empty so no tenant overrides apply.
        $matcher = new CommandMatcher(new BuiltinCommandsRegistry());

        return new MessageRouter(
            commandMatcher: $matcher,
            commandExecutor: $commandExecutor ?? Mockery::mock(GlobalCommandExecutorInterface::class),
            typing: $typing ?? $this->noOpTyping(),
            stateRouter: $stateRouter ?? new SessionStateRouter(),
            dropPolicy: $dropPolicy ?? Mockery::mock(DropPolicyInterface::class)->shouldIgnoreMissing(),
            sessions: $sessions ?? Mockery::mock(FlowSessionRepositoryInterface::class)->shouldIgnoreMissing(),
            triggerResolver: $triggerResolver ?? Mockery::mock(TriggerResolverInterface::class)->shouldIgnoreMissing(),
            orchestrator: $orchestrator ?? Mockery::mock(FlowOrchestratorInterface::class)->shouldIgnoreMissing(),
            cache: $cache ?? Mockery::mock(CacheRepository::class),
            typingHeartbeat: new TypingHeartbeatRegistry(),
        );
    }

    private function noOpTyping(): TypingIndicatorService
    {
        // Real service over a registry that returns null sender — start()
        // short-circuits and returns null, the no-op behaviour we want.
        $registry = Mockery::mock(ChannelRegistryInterface::class);
        $registry->shouldReceive('sender')->andReturn(null);

        return new TypingIndicatorService($registry);
    }

    private function lockMock(bool $acquired): MockInterface
    {
        $lock = Mockery::mock(CacheLock::class);
        $lock->shouldReceive('get')->andReturn($acquired);
        $lock->shouldReceive('release')->andReturnTrue();

        return $lock;
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
            'name'              => 'Test',
            'is_active'         => true,
            'default_language'  => 'en',
            'fallback_message'  => null,
        ]);
        $assistant->id = 'assistant-1';
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
        $channel->id = 'channel-1';
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
