<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Conversation;

use App\Domains\Conversation\DTO\MessageLogEntry;
use App\Domains\Conversation\Enums\DeliveryStatus;
use App\Domains\Conversation\Enums\MessageContentType;
use App\Domains\Conversation\Enums\MessageDirection;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Conversation\Enums\MessageSenderType;
use App\Domains\Conversation\Jobs\PersistConversationMessageJob;
use App\Domains\Conversation\Jobs\UpdateConversationDeliveryStatusJob;
use App\Domains\Conversation\Services\ConversationLogger;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Exceptions\TenantNotResolvedException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Psr\Log\NullLogger;
use Tests\TestCase;

final class ConversationLoggerTest extends TestCase
{
    public function test_log_dispatches_persist_job_when_enabled(): void
    {
        Bus::fake();

        $this->makeLogger(enabled: true)->log($this->entry());

        Bus::assertDispatched(PersistConversationMessageJob::class);
    }

    public function test_log_does_nothing_when_disabled(): void
    {
        Bus::fake();

        $this->makeLogger(enabled: false)->log($this->entry());

        Bus::assertNotDispatched(PersistConversationMessageJob::class);
    }

    public function test_update_delivery_status_dispatches_job_with_current_tenant(): void
    {
        Bus::fake();

        $this->makeLogger(enabled: true)->updateDeliveryStatus('pmid-1', DeliveryStatus::Delivered);

        Bus::assertDispatched(
            UpdateConversationDeliveryStatusJob::class,
            static fn (UpdateConversationDeliveryStatusJob $job): bool => 'tenant-1' === $job->tenantId
                && 'pmid-1' === $job->providerMessageId
                && DeliveryStatus::Delivered === $job->status,
        );
    }

    public function test_update_delivery_status_skips_when_no_tenant_resolved(): void
    {
        Bus::fake();

        $this->makeLogger(enabled: true, resolvedTenantId: null)
            ->updateDeliveryStatus('pmid-1', DeliveryStatus::Delivered);

        Bus::assertNotDispatched(UpdateConversationDeliveryStatusJob::class);
    }

    private function makeLogger(bool $enabled, ?string $resolvedTenantId = 'tenant-1'): ConversationLogger
    {
        return new ConversationLogger(
            enabled: $enabled,
            logger: new NullLogger(),
            tenantContext: $this->tenantContext($resolvedTenantId),
        );
    }

    private function tenantContext(?string $tenantId): TenantContextInterface
    {
        return new class ($tenantId) implements TenantContextInterface {
            public function __construct(private readonly ?string $tenantId)
            {
            }

            public function set(TenantInterface $tenant): void
            {
            }

            public function get(): TenantInterface
            {
                if (null === $this->tenantId) {
                    throw new TenantNotResolvedException('No tenant set.');
                }

                $id = $this->tenantId;

                return new class ($id) implements TenantInterface {
                    public function __construct(private readonly string $id)
                    {
                    }

                    public function getId(): string
                    {
                        return $this->id;
                    }

                    public function getSlug(): string
                    {
                        return 'slug';
                    }

                    public function getSchemaName(): string
                    {
                        return 'schema';
                    }

                    public function isActive(): bool
                    {
                        return true;
                    }

                    public function getConfig(string $key, mixed $default = null): mixed
                    {
                        return $default;
                    }
                };
            }

            public function isResolved(): bool
            {
                return null !== $this->tenantId;
            }

            public function reset(): void
            {
            }
        };
    }

    private function entry(): MessageLogEntry
    {
        return new MessageLogEntry(
            tenantId: 'tenant-1',
            assistantId: 'assistant-1',
            contactId: 'contact-1',
            channelId: 'channel-1',
            platform: 'telegram',
            direction: MessageDirection::Inbound,
            senderType: MessageSenderType::Contact,
            contentType: MessageContentType::Text,
            text: 'hi',
            payload: [],
            media: [],
            providerMessageId: null,
            replyToProviderMessageId: null,
            origin: MessageOrigin::Flow,
            originRef: [],
            idempotencyKey: 'update-1',
            status: DeliveryStatus::Received,
            occurredAt: CarbonImmutable::parse('2026-06-15 10:00:00', 'UTC'),
        );
    }
}
