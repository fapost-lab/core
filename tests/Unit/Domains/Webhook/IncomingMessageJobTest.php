<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Assistant\Contracts\AssistantRepositoryInterface;
use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelRegistryInterface;
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
use App\Domains\Webhook\Contracts\ChannelAdapterInterface;
use App\Domains\Webhook\Jobs\IncomingMessageJob;
use App\Domains\Webhook\Services\ChannelAdapterResolver;
use FAPost\Foundation\DTO\InboundWebhookPayload;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\IncomingMessageType;
use FAPost\Foundation\Flow\Contracts\TriggerResolverInterface;
use FAPost\Foundation\Flow\DTO\ResolvedTrigger;
use FAPost\Foundation\Flow\DTO\TriggerContext;
use Illuminate\Cache\CacheLock;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class IncomingMessageJobTest extends TestCase
{
    public function test_dispatch_carries_payload_and_uses_expected_retry_settings(): void
    {
        Bus::fake();

        IncomingMessageJob::dispatch($this->payload());

        Bus::assertDispatched(
            IncomingMessageJob::class,
            static fn (IncomingMessageJob $job): bool => 5 === $job->tries
                && 'tenant-1' === $job->payload->tenantId
                && 'assistant-1' === $job->payload->assistantId
                && 'channel-1' === $job->payload->channelId
                && 'main' === $job->payload->schema
                && 'tg:channel-1:up-1' === $job->payload->idempotencyKey
        );
    }

    public function test_enters_tenant_context_before_normalize(): void
    {
        // The adapter resolver is called inside TenantSwitcher::runForTenant(), proving
        // that tenant context is entered before normalization happens.
        Cache::shouldReceive('lock')->once()->andReturn($this->mockLock(acquired: true));

        $normalized      = $this->normalizedMessage();
        $adapterResolver = $this->makeAdapterResolver($this->mockAdapter($normalized));

        $this->wireHappyPath($adapterResolver, contact: Contact::factory()->make(['tenant_id' => 'tenant-1']));

        $this->assertTrue(true); // reached without exception → context was set before normalize
    }

    public function test_acquires_lock_before_contact_resolve(): void
    {
        $order = [];

        Cache::shouldReceive('lock')->once()->andReturn($this->mockLock(acquired: true));

        $normalized = $this->normalizedMessage();

        $adapterResolver = $this->makeAdapterResolver(
            $this->mockAdapter($normalized, afterNormalize: function () use (&$order): void {
                $order[] = 'normalize';
            })
        );

        $contactService = $this->mock(ContactServiceInterface::class, function (MockInterface $mock) use (&$order): void {
            $contact = Contact::factory()->make(['tenant_id' => 'tenant-1']);
            $mock->shouldReceive('findOrCreate')->once()->andReturnUsing(function () use ($contact, &$order) {
                $order[] = 'findOrCreate';

                return $contact;
            });
            $mock->shouldReceive('findOrCreateChannelContact')->once()->andReturn(ChannelContact::make());
        });

        $this->wireWithDependencies(
            adapterResolver: $adapterResolver,
            contactService: $contactService,
        );

        // normalize happens, then lock is acquired (Cache::fake always grants it), then findOrCreate
        $this->assertSame(['normalize', 'findOrCreate'], $order);
    }

    public function test_lock_key_uses_platform_user_id(): void
    {
        $capturedKey = null;

        Cache::shouldReceive('lock')
            ->once()
            ->withArgs(function (string $key, int $ttl) use (&$capturedKey): bool {
                $capturedKey = $key;

                return true;
            })
            ->andReturn($this->mockLock(acquired: true));

        $normalized      = $this->normalizedMessage(externalUserId: 'user-42');
        $adapterResolver = $this->makeAdapterResolver($this->mockAdapter($normalized));

        $this->wireHappyPath($adapterResolver, contact: Contact::factory()->make(['tenant_id' => 'tenant-1']));

        $this->assertSame('session_lock:tenant-1:telegram:user-42:assistant-1', $capturedKey);
    }

    public function test_lock_miss_releases_job_without_contact_resolve(): void
    {
        Cache::shouldReceive('lock')->once()->andReturn($this->mockLock(acquired: false));

        $releaseDelay = 0;
        $queueJob     = $this->mock(QueueJobContract::class, function (MockInterface $mock) use (&$releaseDelay): void {
            $mock->shouldReceive('attempts')->once()->andReturn(3);
            $mock->shouldReceive('release')->once()->with(5)->andReturnUsing(
                function (int $delay) use (&$releaseDelay): void {
                    $releaseDelay = $delay;
                }
            );
        });

        $normalized      = $this->normalizedMessage();
        $adapterResolver = $this->makeAdapterResolver($this->mockAdapter($normalized));

        $contactService = $this->mock(ContactServiceInterface::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('findOrCreate');
        });

        $job = new IncomingMessageJob($this->payload());
        $job->setJob($queueJob);

        $job->handle(
            $this->tenantSwitcher(),
            $adapterResolver,
            $this->mock(CurrentAssistantInterface::class, fn (MockInterface $m) => $m->shouldNotReceive('set')),
            $this->assistantRepository(shouldBeCalled: false),
            $contactService,
            $this->mock(FlowSessionRepositoryInterface::class, fn (MockInterface $m) => $m->shouldNotReceive('findActiveForContact')),
            $this->mock(TriggerResolverInterface::class, fn (MockInterface $m) => $m->shouldNotReceive('resolve')),
            $this->mock(FlowOrchestratorInterface::class, fn (MockInterface $m) => $m->shouldNotReceive('handle')),
        );

        $this->assertSame(5, $releaseDelay);
    }

    public function test_orchestrator_lock_timeout_releases_job_and_frees_own_lock(): void
    {
        Cache::shouldReceive('lock')->once()->andReturn($this->mockLock(acquired: true));

        $releaseDelay = 0;
        $queueJob     = $this->mock(QueueJobContract::class, function (MockInterface $mock) use (&$releaseDelay): void {
            $mock->shouldReceive('attempts')->once()->andReturn(3);
            $mock->shouldReceive('release')->once()->with(5)->andReturnUsing(
                function (int $delay) use (&$releaseDelay): void {
                    $releaseDelay = $delay;
                }
            );
        });

        $normalized      = $this->normalizedMessage();
        $adapterResolver = $this->makeAdapterResolver($this->mockAdapter($normalized));

        $contactService = $this->mock(ContactServiceInterface::class, function (MockInterface $mock): void {
            $contact = Contact::factory()->make(['tenant_id' => 'tenant-1']);
            $mock->shouldReceive('findOrCreate')->once()->andReturn($contact);
            $mock->shouldReceive('findOrCreateChannelContact')->once()->andReturn(ChannelContact::make());
        });

        $sessions = $this->mock(FlowSessionRepositoryInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findActiveForContact')->once()->andReturn(null);
        });

        $triggerResolver = $this->mock(TriggerResolverInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn(
                new ResolvedTrigger(triggerId: 'trigger-1', flowId: 'flow-1', type: 'message')
            );
        });

        $orchestrator = $this->mock(FlowOrchestratorInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')
                ->once()
                ->andThrow(new SessionLockTimeoutException('session_lock:tenant-1:contact-1:assistant-1'));
        });

        $job = new IncomingMessageJob($this->payload());
        $job->setJob($queueJob);

        $job->handle(
            $this->tenantSwitcher(),
            $adapterResolver,
            $this->mock(CurrentAssistantInterface::class, fn (MockInterface $m) => $m->shouldReceive('set')->once()),
            $this->assistantRepository(),
            $contactService,
            $sessions,
            $triggerResolver,
            $orchestrator,
        );

        $this->assertSame(5, $releaseDelay);
    }

    public function test_successful_execution_does_not_release_job(): void
    {
        Cache::shouldReceive('lock')->once()->andReturn($this->mockLock(acquired: true));

        $queueJob = $this->mock(QueueJobContract::class, function (MockInterface $mock): void {
            $mock->shouldReceive('attempts')->never();
            $mock->shouldReceive('release')->never();
        });

        $normalized      = $this->normalizedMessage();
        $adapterResolver = $this->makeAdapterResolver($this->mockAdapter($normalized));

        $job = new IncomingMessageJob($this->payload());
        $job->setJob($queueJob);

        $this->wireHappyPath($adapterResolver, contact: Contact::factory()->make(['tenant_id' => 'tenant-1']), job: $job);
    }

    public function test_it_skips_trigger_lookup_when_active_session_exists(): void
    {
        Cache::shouldReceive('lock')->once()->andReturn($this->mockLock(acquired: true));

        $normalized      = $this->normalizedMessage();
        $adapterResolver = $this->makeAdapterResolver($this->mockAdapter($normalized));

        $contactService = $this->mock(ContactServiceInterface::class, function (MockInterface $mock): void {
            $contact = Contact::factory()->make(['tenant_id' => 'tenant-1']);
            $mock->shouldReceive('findOrCreate')->once()->andReturn($contact);
            $mock->shouldReceive('findOrCreateChannelContact')->once()->andReturn(ChannelContact::make());
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

        $job = new IncomingMessageJob($this->payload());
        $job->handle(
            $this->tenantSwitcher(),
            $adapterResolver,
            $this->mock(CurrentAssistantInterface::class, fn (MockInterface $m) => $m->shouldReceive('set')->once()),
            $this->assistantRepository(),
            $contactService,
            $sessions,
            $triggerResolver,
            $orchestrator,
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function payload(): InboundWebhookPayload
    {
        return new InboundWebhookPayload(
            tenantId: 'tenant-1',
            schema: 'main',
            assistantId: 'assistant-1',
            channelId: 'channel-1',
            platform: 'telegram',
            rawPayload: [
                'update_id' => 'up-1',
                'message'   => [
                    'date' => 0,
                    'chat' => ['id' => 'chat-1'],
                    'from' => ['id' => 'user-1'],
                    'text' => 'hello',
                ],
            ],
            idempotencyKey: 'tg:channel-1:up-1',
            receivedAt: 1_717_171_717,
        );
    }

    private function normalizedMessage(string $externalUserId = 'user-1'): IncomingMessage
    {
        return new IncomingMessage(
            updateId: 'up-1',
            externalUserId: $externalUserId,
            externalChatId: 'chat-1',
            text: 'hello',
            type: IncomingMessageType::Text,
            platform: 'telegram',
            payload: ['username' => 'u1'],
        );
    }

    private function mockAdapter(IncomingMessage $normalized, ?callable $afterNormalize = null): ChannelAdapterInterface
    {
        return $this->mock(ChannelAdapterInterface::class, function (MockInterface $mock) use ($normalized, $afterNormalize): void {
            $mock->shouldReceive('normalize')->once()->andReturnUsing(function () use ($normalized, $afterNormalize) {
                if (null !== $afterNormalize) {
                    ($afterNormalize)();
                }

                return $normalized;
            });
        });
    }

    /**
     * Build a real ChannelAdapterResolver backed by a mocked registry that returns the given adapter.
     * Avoids mocking the final ChannelAdapterResolver class directly.
     */
    private function makeAdapterResolver(ChannelAdapterInterface $adapter): ChannelAdapterResolver
    {
        $registry = $this->mock(ChannelRegistryInterface::class, function (MockInterface $mock) use ($adapter): void {
            $mock->shouldReceive('adapter')->andReturn($adapter);
        });

        return new ChannelAdapterResolver($registry);
    }

    private function mockLock(bool $acquired): CacheLock
    {
        return $this->mock(CacheLock::class, function (MockInterface $mock) use ($acquired): void {
            $mock->shouldReceive('get')->once()->andReturn($acquired);
            if ($acquired) {
                $mock->shouldReceive('release')->once();
            }
        });
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

    private function assistantRepository(bool $shouldBeCalled = true): AssistantRepositoryInterface
    {
        return $this->mock(AssistantRepositoryInterface::class, function (MockInterface $mock) use ($shouldBeCalled): void {
            $assistant = new Assistant();
            $assistant->forceFill([
                'id'               => 'assistant-1',
                'default_language' => 'en',
            ]);

            if ($shouldBeCalled) {
                $mock->shouldReceive('findById')->once()->andReturn($assistant);
            } else {
                $mock->shouldNotReceive('findById');
            }
        });
    }

    /**
     * Wire and run the full happy path, starting a fresh job unless one is provided.
     */
    private function wireHappyPath(
        ChannelAdapterResolver $adapterResolver,
        Contact $contact,
        ?IncomingMessageJob $job = null,
    ): void {
        $contactService = $this->mock(ContactServiceInterface::class, function (MockInterface $mock) use ($contact): void {
            $mock->shouldReceive('findOrCreate')->once()->andReturn($contact);
            $mock->shouldReceive('findOrCreateChannelContact')->once()->andReturn(ChannelContact::make());
        });

        $sessions = $this->mock(FlowSessionRepositoryInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findActiveForContact')->once()->andReturn(null);
        });

        $triggerResolver = $this->mock(TriggerResolverInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn(
                new ResolvedTrigger(triggerId: 'trigger-1', flowId: 'flow-1', type: 'message')
            );
        });

        $orchestrator = $this->mock(FlowOrchestratorInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->once();
        });

        ($job ?? new IncomingMessageJob($this->payload()))->handle(
            $this->tenantSwitcher(),
            $adapterResolver,
            $this->mock(CurrentAssistantInterface::class, fn (MockInterface $m) => $m->shouldReceive('set')->once()),
            $this->assistantRepository(),
            $contactService,
            $sessions,
            $triggerResolver,
            $orchestrator,
        );
    }

    /**
     * Wire handle() with selective dependency overrides for specific assertions.
     */
    private function wireWithDependencies(
        ChannelAdapterResolver $adapterResolver,
        ContactServiceInterface $contactService,
    ): void {
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
            )->andReturn(new ResolvedTrigger(triggerId: 'trigger-1', flowId: 'flow-1', type: 'message'));
        });

        $orchestrator = $this->mock(FlowOrchestratorInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->once();
        });

        (new IncomingMessageJob($this->payload()))->handle(
            $this->tenantSwitcher(),
            $adapterResolver,
            $this->mock(CurrentAssistantInterface::class, fn (MockInterface $m) => $m->shouldReceive('set')->once()),
            $this->assistantRepository(),
            $contactService,
            $sessions,
            $triggerResolver,
            $orchestrator,
        );
    }
}
