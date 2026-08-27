<?php

declare(strict_types=1);

namespace App\Domains\Flow\Orchestration;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\FallbackMessageServiceInterface;
use App\Domains\Flow\Contracts\FlowAccessPolicyInterface;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Contracts\FlowOrchestratorInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Contracts\PersistentButtonRegistryInterface;
use App\Domains\Flow\Exceptions\FlowConcurrencyException;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Support\CallbackDataCodec;
use App\Domains\Tenancy\Settings\TenantSettings;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\Flow\DTO\ResolvedTrigger;
use Throwable;

final class FlowOrchestrator implements FlowOrchestratorInterface
{
    private const int MAX_OPTIMISTIC_RETRIES = 3;

    public function __construct(
        private readonly FlowExecutionGuardInterface $guard,
        private readonly FlowEngineInterface $engine,
        private readonly FlowSessionRepositoryInterface $sessions,
        private readonly FlowDefinitionRepositoryInterface $definitions,
        private readonly CurrentAssistantInterface $currentAssistant,
        private readonly FallbackMessageServiceInterface $fallbackSender,
        private readonly PersistentButtonRegistryInterface $persistentButtonRegistry,
        private readonly ContentTranslatorInterface $translator,
        private readonly TenantSettings $tenantSettings,
        private readonly FlowAccessPolicyInterface $accessPolicy,
    ) {
    }

    public function handle(
        Contact $contact,
        IncomingMessage $message,
        string $assistantId,
        ?ResolvedTrigger $trigger = null
    ): void {
        $this->guard->run(
            tenantId: (string)$contact->tenant_id,
            contactId: (string)$contact->getKey(),
            assistantId: $assistantId,
            callback: fn () => $this->executeWithOptimisticRetry($contact, $message, $assistantId, $trigger),
        );
    }

    private function executeWithOptimisticRetry(
        Contact $contact,
        IncomingMessage $message,
        string $assistantId,
        ?ResolvedTrigger $trigger
    ): void {
        $attempt = 0;

        while (true) {
            try {
                $session = $this->sessions->findActiveForContact($contact, $assistantId);

                // For callback_query messages from a different (historical) session:
                // check the persistent button registry before falling through to normal flow logic.
                if ($this->isPersistentButtonCandidate($session, $message)
                    && $this->handlePersistentButton($contact, $message, $session)) {
                    return;
                }

                if (null === $session) {
                    $flowId = $trigger?->flowId ?? $this->resolveDefaultFlowId();

                    if (null !== $flowId) {
                        $definition = $this->definitions->findLatestActiveByFlowId($flowId);

                        // A private flow is unavailable to an unauthenticated contact —
                        // treat it like "no flow available" and fall through to fallback,
                        // never starting a session for it.
                        if (null !== $definition && $this->accessPolicy->canStart($definition, $contact)) {
                            $this->engine->start($definition, $contact, []);

                            return;
                        }
                    }

                    $this->sendFallbackIfConfigured($contact, $assistantId);

                    return;
                }

                $this->engine->resume($session, $message);

                return;
            } catch (FlowConcurrencyException $exception) {
                if (++$attempt >= self::MAX_OPTIMISTIC_RETRIES) {
                    throw $exception;
                }

                usleep(50_000 * $attempt);
            }
        }
    }

    /**
     * Returns true when the message is a callback_query whose decoded session_id
     * does NOT match the currently active session — meaning it may originate from
     * a completed session and needs a persistent-button registry check.
     */
    private function isPersistentButtonCandidate(?FlowSession $session, IncomingMessage $message): bool
    {
        if (IncomingMessageType::CallbackQuery !== $message->type) {
            return false;
        }

        $decoded = CallbackDataCodec::decode($message->text);

        if (null === $decoded) {
            return false;
        }

        // Active session is still the origin of this callback → normal resume path handles it.
        return ! (null !== $session && (string)$session->getKey() === $decoded['session_id']);
    }

    /**
     * Looks up the persistent button registry and, when found, cancels any active session
     * and re-enters the flow from the registered branch.
     *
     * @return bool  true when the button was handled (caller should return), false when no registration found
     */
    private function handlePersistentButton(
        Contact $contact,
        IncomingMessage $message,
        ?FlowSession $activeSession,
    ): bool {
        $decoded = CallbackDataCodec::decode($message->text);

        if (null === $decoded) {
            return false;
        }

        $registration = $this->persistentButtonRegistry->find(
            tenantId: (string)$contact->tenant_id,
            contactId: (string)$contact->getKey(),
            originalSessionId: $decoded['session_id'],
            buttonId: $decoded['button_id'],
        );

        if (null === $registration) {
            return false;
        }

        // Cancel the current session (if any) before starting the persistent branch
        // to prevent two concurrent active sessions for the same contact+assistant.
        if (null !== $activeSession) {
            $this->sessions->cancel($activeSession);
        }

        try {
            $definition = $this->definitions->findById($registration->flow_definition_id);
            $this->engine->resumeFromNode($definition, $contact, $registration->node_id, $decoded['button_id']);
        } catch (Throwable) {
            // Flow definition may have been deleted — silently ignore.
        }

        return true;
    }

    private function resolveDefaultFlowId(): ?string
    {
        if (! $this->currentAssistant->isResolved()) {
            return null;
        }

        $flowId = $this->currentAssistant->get()->default_flow_id;

        return is_string($flowId) && '' !== $flowId ? $flowId : null;
    }

    private function sendFallbackIfConfigured(Contact $contact, string $assistantId): void
    {
        if (! $this->currentAssistant->isResolved()) {
            return;
        }

        $field = $this->currentAssistant->get()->fallback_message;

        if (! is_array($field) && ! is_string($field)) {
            return;
        }

        // Resolve the locale map against the contact's language; old plain
        // strings written before the localization migration also pass through
        // resolveField unchanged.
        $language = is_string($contact->language) && '' !== $contact->language
            ? $contact->language
            : $this->tenantSettings->fallback_language;

        $message = $this->translator->resolveField($field, $language);

        if ('' !== $message) {
            $this->fallbackSender->send($contact, $assistantId, $message);
        }
    }
}
