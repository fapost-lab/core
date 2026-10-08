<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Jobs;

use App\Domains\Assistant\Contracts\AssistantRepositoryInterface;
use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Contracts\ContactServiceInterface;
use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Conversation\Capture\ConversationCaptureFactory;
use App\Domains\Conversation\Contracts\ConversationLoggerInterface;
use App\Domains\Flow\Routing\MessageRouter;
use App\Domains\Flow\Routing\RoutingOutcome;
use App\Domains\Tenancy\Queue\RespectsTenantAccessMode;
use App\Domains\Tenancy\Queue\StoppedTenantAction;
use App\Domains\Tenancy\Queue\TenantAccessGatedJob;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Domains\Webhook\Services\ChannelAdapterResolver;
use Carbon\CarbonImmutable;
use Fapost\Foundation\DTO\InboundWebhookPayload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Webhook ingress job. Performs only transport-level work and then hands
 * off to the {@see MessageRouter}, which owns the full message routing
 * pipeline (commands, typing, lock, session state, execution, cleanup).
 *
 * Execution order (must be strictly preserved):
 *   1. Switch to tenant schema.
 *   2. Normalize raw payload → IncomingMessage via channel adapter.
 *   3. Resolve assistant + contact + channel.
 *   4. Delegate to MessageRouter::route().
 *   5. Release-with-delay if the router signalled a transient lock miss
 *      that warrants retrying this job (vs the user-facing busy notice).
 */
final class IncomingMessageJob implements ShouldQueue, TenantAccessGatedJob
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly InboundWebhookPayload $payload,
    ) {
    }

    public function accessModeTenantId(): string
    {
        return $this->payload->tenantId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RespectsTenantAccessMode(StoppedTenantAction::Drop)];
    }

    public function handle(
        TenantSwitcher $switcher,
        ChannelAdapterResolver $adapterResolver,
        CurrentAssistantInterface $currentAssistant,
        AssistantRepositoryInterface $assistants,
        ContactServiceInterface $contactService,
        MessageRouter $router,
        ConversationLoggerInterface $conversationLogger,
        ConversationCaptureFactory $captureFactory,
    ): void {
        $tenant = new RuntimeTenant(
            id: $this->payload->tenantId,
            schemaName: $this->payload->schema,
        );

        $switcher->runForTenant(
            $tenant,
            function () use (
                $adapterResolver,
                $currentAssistant,
                $assistants,
                $contactService,
                $router,
                $conversationLogger,
                $captureFactory,
            ): void {
                $platform       = PlatformEnum::from($this->payload->platform);
                $adapter        = $adapterResolver->resolve($platform);
                $inboundMessage = $adapter->normalize($this->payload->rawPayload);

                $assistant = $assistants->findById($this->payload->assistantId);
                $currentAssistant->set($assistant);

                $contact = $contactService->findOrCreate(
                    tenantId: $this->payload->tenantId,
                    platform: $platform,
                    externalId: $inboundMessage->externalUserId,
                    meta: $inboundMessage->payload,
                    defaultLanguage: $assistant->default_language,
                );

                $contactService->findOrCreateChannelContact(
                    contact: $contact,
                    channelId: $this->payload->channelId,
                );

                $channel = Channel::query()->findOrFail($this->payload->channelId);

                // Capture the inbound message BEFORE routing: messages the
                // drop-policy discards under concurrency are still real user
                // messages and must land in the transcript regardless of whether
                // a flow ran (spec §7.1).
                $conversationLogger->log(
                    $captureFactory->forInbound(
                        tenantId: $this->payload->tenantId,
                        assistantId: (string) $assistant->getKey(),
                        contactId: (string) $contact->getKey(),
                        channelId: $this->payload->channelId,
                        message: $inboundMessage,
                        idempotencyKey: $this->payload->idempotencyKey,
                        // Stable across retries (ingress receive time) so a retried
                        // delivery dedups on the transcript unique index instead of
                        // inserting a duplicate row.
                        occurredAt: CarbonImmutable::createFromTimestamp($this->payload->receivedAt, 'UTC'),
                    ),
                );

                $outcome = $router->route(
                    contact: $contact,
                    message: $inboundMessage,
                    assistant: $assistant,
                    channel: $channel,
                );

                if ($this->shouldRetry($outcome)) {
                    $this->release($this->lockMissDelay($this->attempts()));
                }
            }
        );
    }

    private function shouldRetry(RoutingOutcome $outcome): bool
    {
        // Retry only on engine-level lock contention so the worker doesn't
        // spin on a stuck session. User-facing busy drop ('lock_timeout')
        // already informed the contact — no retry needed.
        return $outcome->wasDropped() && 'engine_lock_timeout' === $outcome->reason;
    }

    private function lockMissDelay(int $attempt): int
    {
        return match ($attempt) {
            1       => 1,
            2       => 2,
            3       => 5,
            default => 10,
        };
    }
}
