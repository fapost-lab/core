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
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\Flow\Contracts\TriggerResolverInterface;
use FAPost\Foundation\Flow\DTO\TriggerContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class IncomingMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly IncomingMessage $message,
        public readonly string $tenantId,
        public readonly string $assistantId,
        public readonly string $channelId,
        public readonly string $schema,
    ) {
    }

    public function handle(
        TenantSwitcher $switcher,
        CurrentAssistantInterface $currentAssistant,
        AssistantRepositoryInterface $assistants,
        ContactServiceInterface $contactService,
        FlowSessionRepositoryInterface $sessions,
        TriggerResolverInterface $triggerResolver,
        FlowOrchestratorInterface $orchestrator,
    ): void {
        $tenant = new RuntimeTenant(
            id: $this->tenantId,
            schemaName: $this->schema,
        );

        $switcher->runForTenant(
            $tenant,
            function () use ($contactService, $currentAssistant, $sessions, $triggerResolver, $orchestrator, $assistants): void {
                $assistant = $assistants->findById($this->assistantId);
                $currentAssistant->set($assistant);

                $contact = $contactService->findOrCreate(
                    tenantId: $this->tenantId,
                    platform: PlatformEnum::from($this->message->platform),
                    externalId: $this->message->externalUserId,
                    meta: $this->message->payload,
                    defaultLanguage: $assistant->default_language,
                );

                $contactService->findOrCreateChannelContact(
                    contact: $contact,
                    channelId: $this->channelId,
                );

                $activeSession = $sessions->findActiveForContact($contact, $this->assistantId);

                $trigger = null;

                if (null === $activeSession) {
                    $trigger = $triggerResolver->resolve(
                        new TriggerContext(
                            type: FlowTriggerType::Message->value,
                            tenantId: $this->tenantId,
                            assistantId: $this->assistantId,
                            payload: [
                                'text'             => $this->message->text,
                                'incoming_payload' => $this->message->payload,
                            ],
                        )
                    );
                }

                try {
                    $orchestrator->handle($contact, $this->message, $this->assistantId, $trigger);
                } catch (SessionLockTimeoutException) {
                    $this->release($this->lockMissDelay($this->attempts()));

                    return;
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
}
