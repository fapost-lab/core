<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Assistant\Contracts\AssistantRepositoryInterface;
use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Contracts\ContactServiceInterface;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowOrchestratorInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Webhook\Jobs\IncomingMessageJob;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\IncomingMessageType;
use FAPost\Foundation\Flow\Contracts\TriggerResolverInterface;
use FAPost\Foundation\Flow\DTO\ResolvedTrigger;
use FAPost\Foundation\Flow\DTO\TriggerContext;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class IncomingMessageJobTest extends TestCase
{
    /**
     * @return array<string, array{int, int}>
     */
    public static function lockMissDelayProvider(): array
    {
        return [
            'attempt-1' => [1, 1],
            'attempt-2' => [2, 2],
            'attempt-3' => [3, 5],
            'attempt-4' => [4, 10],
            'attempt-5' => [5, 10],
        ];
    }

    public function test_dispatch_carries_message_tenant_and_channel_and_uses_expected_retry_settings(): void
    {
        Bus::fake();

        $message = new IncomingMessage(
            updateId: 'up-1',
            externalUserId: 'user-1',
            externalChatId: 'chat-1',
            text: 'hello',
            type: IncomingMessageType::Text,
            platform: 'telegram',
            payload: ['username' => 'u1'],
        );

        IncomingMessageJob::dispatch(
            message: $message,
            tenantId: 'tenant-1',
            assistantId: 'assistant-1',
            channelId: 'channel-1',
            schema: 'main',
        );

        Bus::assertDispatched(
            IncomingMessageJob::class,
            static fn (IncomingMessageJob $job): bool => 5 === $job->tries
                                                                                                     && 'tenant-1'
                                                                                                        === $job->tenantId
                                                                                                     && 'assistant-1'
                                                                                                        === $job->assistantId
                                                                                                     && 'channel-1'
                                                                                                        === $job->channelId
                                                                                                     && 'main'
                                                                                                        === $job->schema
                                                                                                     && 'up-1'
                                                                                                        === $job->message->updateId
        );
    }

    public function test_lock_miss_releases_job_with_expected_delay_and_stops_execution(): void
    {
        $releaseDelay = 0;
        $queueJob     = $this->mock(QueueJobContract::class, function (MockInterface $mock) use (&$releaseDelay): void {
            $mock->shouldReceive('attempts')->once()->andReturn(3);
            $mock->shouldReceive('release')->once()->with(5)->andReturnUsing(
                function (int $delay) use (&$releaseDelay): void {
                    $releaseDelay = $delay;
                }
            );
        });

        $job = new IncomingMessageJob(
            message: $this->message(),
            tenantId: 'tenant-1',
            assistantId: 'assistant-1',
            channelId: 'channel-1',
            schema: 'main',
        );
        $job->setJob($queueJob);

        $switcher         = $this->tenantSwitcher();
        $assistants       = $this->assistantRepository();
        $currentAssistant = $this->mock(CurrentAssistantInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('set')->once();
        });
        $contactService = $this->mock(ContactServiceInterface::class, function (MockInterface $mock): void {
            $contact = Contact::factory()->make(['tenant_id' => 'tenant-1']);
            $mock->shouldReceive('findOrCreate')->once()->andReturn($contact);
            $mock->shouldReceive('findOrCreateChannelContact')->once()->with($contact, 'channel-1')->andReturn(
                ChannelContact::make()
            );
        });
        $sessions = $this->mock(FlowSessionRepositoryInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findActiveForContact')->once()->andReturn(null);
        });
        $triggerResolver = $this->mock(TriggerResolverInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->withArgs(
                fn (TriggerContext $context): bool => 'message' === $context->type
                                                     && 'tenant-1' === $context->tenantId
                                                     && 'assistant-1' === $context->assistantId
                                                     && isset($context->payload['incoming_payload'])
                                                     && ['username' => 'u1'] === $context->payload['incoming_payload']
                                                     && 'hello' === $context->payload['text']
            )->andReturn(
                new ResolvedTrigger(
                    triggerId: 'trigger-1',
                    flowId: 'flow-1',
                    type: 'message',
                )
            );
        });
        $orchestrator = $this->mock(FlowOrchestratorInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')
                ->once()
                ->andThrow(new SessionLockTimeoutException('session_lock:tenant-1:contact-1:assistant-1'));
        });

        $job->handle($switcher, $currentAssistant, $assistants, $contactService, $sessions, $triggerResolver, $orchestrator);

        $this->assertSame(5, $releaseDelay);
    }

    public function test_successful_execution_does_not_release_job(): void
    {
        $queueJob = $this->mock(QueueJobContract::class, function (MockInterface $mock): void {
            $mock->shouldReceive('attempts')->never();
            $mock->shouldReceive('release')->never();
        });

        $job = new IncomingMessageJob(
            message: $this->message(),
            tenantId: 'tenant-1',
            assistantId: 'assistant-1',
            channelId: 'channel-1',
            schema: 'main',
        );
        $job->setJob($queueJob);

        $switcher         = $this->tenantSwitcher();
        $assistants       = $this->assistantRepository();
        $currentAssistant = $this->mock(CurrentAssistantInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('set')->once();
        });
        $contactService = $this->mock(ContactServiceInterface::class, function (MockInterface $mock): void {
            $contact = Contact::factory()->make(['tenant_id' => 'tenant-1']);
            $mock->shouldReceive('findOrCreate')->once()->andReturn($contact);
            $mock->shouldReceive('findOrCreateChannelContact')->once()->with($contact, 'channel-1')->andReturn(
                ChannelContact::make()
            );
        });
        $sessions = $this->mock(FlowSessionRepositoryInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findActiveForContact')->once()->andReturn(null);
        });
        $triggerResolver = $this->mock(TriggerResolverInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn(
                new ResolvedTrigger(
                    triggerId: 'trigger-1',
                    flowId: 'flow-1',
                    type: 'message',
                )
            );
        });
        $orchestrator = $this->mock(FlowOrchestratorInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->once();
        });

        $job->handle($switcher, $currentAssistant, $assistants, $contactService, $sessions, $triggerResolver, $orchestrator);
    }

    #[DataProvider('lockMissDelayProvider')]
    public function test_lock_miss_delay_depends_on_attempts(int $attempts, int $expectedDelay): void
    {
        $releaseDelay = 0;
        $queueJob     = $this->mock(
            QueueJobContract::class,
            function (MockInterface $mock) use ($attempts, &$releaseDelay): void {
                $mock->shouldReceive('attempts')->once()->andReturn($attempts);
                $mock->shouldReceive('release')
                    ->once()
                    ->with($this->expectedDelayForAttempt($attempts))
                    ->andReturnUsing(function (int $delay) use (&$releaseDelay): void {
                        $releaseDelay = $delay;
                    });
            }
        );

        $job = new IncomingMessageJob(
            message: $this->message(),
            tenantId: 'tenant-1',
            assistantId: 'assistant-1',
            channelId: 'channel-1',
            schema: 'main',
        );
        $job->setJob($queueJob);

        $switcher         = $this->tenantSwitcher();
        $assistants       = $this->assistantRepository();
        $currentAssistant = $this->mock(CurrentAssistantInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('set')->once();
        });
        $contactService = $this->mock(ContactServiceInterface::class, function (MockInterface $mock): void {
            $contact = Contact::factory()->make(['tenant_id' => 'tenant-1']);
            $mock->shouldReceive('findOrCreate')->once()->andReturn($contact);
            $mock->shouldReceive('findOrCreateChannelContact')->once()->with($contact, 'channel-1')->andReturn(
                ChannelContact::make()
            );
        });
        $sessions = $this->mock(FlowSessionRepositoryInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findActiveForContact')->once()->andReturn(null);
        });
        $triggerResolver = $this->mock(TriggerResolverInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn(
                new ResolvedTrigger(
                    triggerId: 'trigger-1',
                    flowId: 'flow-1',
                    type: 'message',
                )
            );
        });
        $orchestrator = $this->mock(FlowOrchestratorInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')
                ->once()
                ->andThrow(new SessionLockTimeoutException('session_lock:tenant-1:contact-1:assistant-1'));
        });

        $job->handle($switcher, $currentAssistant, $assistants, $contactService, $sessions, $triggerResolver, $orchestrator);

        $this->assertSame($expectedDelay, $releaseDelay);
    }

    public function test_it_skips_trigger_lookup_when_active_session_exists(): void
    {
        $job = new IncomingMessageJob(
            message: $this->message(),
            tenantId: 'tenant-1',
            assistantId: 'assistant-1',
            channelId: 'channel-1',
            schema: 'main',
        );

        $switcher         = $this->tenantSwitcher();
        $assistants       = $this->assistantRepository();
        $currentAssistant = $this->mock(CurrentAssistantInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('set')->once();
        });
        $contactService = $this->mock(ContactServiceInterface::class, function (MockInterface $mock): void {
            $contact = Contact::factory()->make(['tenant_id' => 'tenant-1']);
            $mock->shouldReceive('findOrCreate')->once()->andReturn($contact);
            $mock->shouldReceive('findOrCreateChannelContact')->once()->with($contact, 'channel-1')->andReturn(
                ChannelContact::make()
            );
        });
        $sessions = $this->mock(FlowSessionRepositoryInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findActiveForContact')->once()->andReturn(FlowSession::make());
        });
        $triggerResolver = $this->mock(TriggerResolverInterface::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('resolve');
        });
        $orchestrator = $this->mock(FlowOrchestratorInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->once()->withArgs(
                static fn (
                    Contact $contact,
                    IncomingMessage $message,
                    string $assistantId,
                    ?ResolvedTrigger $trigger
                ): bool => null === $trigger
            );
        });

        $job->handle($switcher, $currentAssistant, $assistants, $contactService, $sessions, $triggerResolver, $orchestrator);
    }

    private function message(): IncomingMessage
    {
        return new IncomingMessage(
            updateId: 'up-1',
            externalUserId: 'user-1',
            externalChatId: 'chat-1',
            text: 'hello',
            type: IncomingMessageType::Text,
            platform: 'telegram',
            payload: ['username' => 'u1'],
        );
    }

    private function tenantSwitcher(): TenantSwitcher
    {
        $tenantContext = $this->mock(TenantContextInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isResolved')->once()->andReturn(false);
            $mock->shouldReceive('set')->once();
            $mock->shouldReceive('reset')->once();
        });

        $databaseManager = $this->mock(TenantDatabaseManagerInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('switchTo')->once();
            $mock->shouldReceive('restore')->once();
        });

        $permissionRegistrar = $this->mock(PermissionRegistrar::class, function (MockInterface $mock): void {
            $mock->shouldReceive('forgetCachedPermissions')->twice();
        });

        return new TenantSwitcher($tenantContext, $databaseManager, $permissionRegistrar);
    }

    private function assistantRepository(): AssistantRepositoryInterface
    {
        return $this->mock(AssistantRepositoryInterface::class, function (MockInterface $mock): void {
            $assistant = new Assistant();
            $assistant->forceFill([
                'id'               => 'assistant-1',
                'default_language' => 'en',
            ]);

            $mock->shouldReceive('findById')->andReturn($assistant);
        });
    }

    private function expectedDelayForAttempt(int $attempt): int
    {
        return match ($attempt) {
            1       => 1,
            2       => 2,
            3       => 5,
            default => 10,
        };
    }
}
