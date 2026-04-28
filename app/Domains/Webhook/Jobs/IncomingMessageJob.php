<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Jobs;

use App\Domains\Assistant\Contracts\AssistantRepositoryInterface;
use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Contact\Contracts\ContactServiceInterface;
use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Flow\Contracts\FlowOrchestratorInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Domains\Webhook\Services\ChannelAdapterResolver;
use FAPost\Foundation\DTO\InboundWebhookPayload;
use FAPost\Foundation\Flow\Contracts\TriggerResolverInterface;
use FAPost\Foundation\Flow\DTO\TriggerContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Processes an inbound webhook event inside the tenant context.
 *
 * Execution order (must be strictly preserved):
 *   1. Switch to tenant schema.
 *   2. Normalize raw payload → IncomingMessage.
 *   3. Acquire distributed lock (platform_user_id based, before any DB write).
 *   4. findOrCreate contact.
 *   5. Resume / start flow session.
 *   6. Release lock.
 */
final class IncomingMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly InboundWebhookPayload $payload,
    ) {
    }

    public function handle(
        TenantSwitcher $switcher,
        ChannelAdapterResolver $adapterResolver,
        CurrentAssistantInterface $currentAssistant,
        AssistantRepositoryInterface $assistants,
        ContactServiceInterface $contactService,
        FlowSessionRepositoryInterface $sessions,
        TriggerResolverInterface $triggerResolver,
        FlowOrchestratorInterface $orchestrator,
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
                $sessions,
                $triggerResolver,
                $orchestrator,
            ): void {
                $adapter        = $adapterResolver->resolve(PlatformEnum::from($this->payload->platform));
                $inboundMessage = $adapter->normalize($this->payload->rawPayload);

                $lockKey = sprintf(
                    'session_lock:%s:%s:%s:%s',
                    $this->payload->tenantId,
                    $this->payload->platform,
                    $inboundMessage->externalUserId,
                    $this->payload->assistantId,
                );

                $lock = Cache::lock($lockKey, 30);

                if ( ! $lock->get()) {
                    $this->release($this->lockMissDelay($this->attempts()));

                    return;
                }

                try {
                    $assistant = $assistants->findById($this->payload->assistantId);
                    $currentAssistant->set($assistant);

                    $contact = $contactService->findOrCreate(
                        tenantId: $this->payload->tenantId,
                        platform: PlatformEnum::from($this->payload->platform),
                        externalId: $inboundMessage->externalUserId,
                        meta: $inboundMessage->payload,
                        defaultLanguage: $assistant->default_language,
                    );

                    $contactService->findOrCreateChannelContact(
                        contact: $contact,
                        channelId: $this->payload->channelId,
                    );

                    $activeSession = $sessions->findActiveForContact($contact, $this->payload->assistantId);

                    if (null !== $activeSession && $this->isResetCommand($inboundMessage->text)) {
                        $sessions->cancel($activeSession);
                        $activeSession = null;
                    }

                    $trigger = null;

                    if (null === $activeSession) {
                        $trigger = $triggerResolver->resolve(
                            new TriggerContext(
                                type: FlowTriggerType::Message->value,
                                tenantId: $this->payload->tenantId,
                                assistantId: $this->payload->assistantId,
                                payload: [
                                    'text'             => $inboundMessage->text,
                                    'incoming_payload' => $inboundMessage->payload,
                                ],
                            )
                        );
                    }

                    try {
                        $orchestrator->handle($contact, $inboundMessage, $this->payload->assistantId, $trigger);
                    } catch (SessionLockTimeoutException) {
                        $this->release($this->lockMissDelay($this->attempts()));

                        return;
                    }
                } finally {
                    $lock->release();
                }
            }
        );
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

    private function isResetCommand(?string $text): bool
    {
        if (null === $text) {
            return false;
        }

        return in_array(mb_strtolower(mb_trim($text)), ['/start', '/reset', '/stop'], true);
    }
}
