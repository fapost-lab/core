<?php

declare(strict_types=1);

namespace App\Domains\Flow\Commands;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\FallbackMessageServiceInterface;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Tenancy\Settings\TenantSettings;

/**
 * Carries out the action implied by a {@see ResolvedCommand} returned by
 * {@see CommandMatcher}. Runs at routing pipeline Step 1 — before lock
 * acquisition, so a `/reset` always works even when a previous worker has
 * stuck the session.
 *
 * Per ADR Message Routing § Built-in Commands, the executor:
 *  - {@code terminate_session}: marks the active session terminated_by_user,
 *    optionally sends the configured ack message
 *  - {@code start_flow}: terminates active session (if any), starts the
 *    requested flow as a fresh top-level session
 *  - {@code send_message}: pushes the configured text without touching the
 *    session — informational replies (e.g. /help)
 */
final readonly class GlobalCommandExecutor implements GlobalCommandExecutorInterface
{
    public function __construct(
        private FlowSessionRepositoryInterface $sessions,
        private FlowDefinitionRepositoryInterface $definitions,
        private FlowEngineInterface $engine,
        private FallbackMessageServiceInterface $messenger,
        private ContentTranslatorInterface $translator,
        private TenantSettings $tenantSettings,
    ) {
    }

    public function execute(
        ResolvedCommand $command,
        Contact $contact,
        string $assistantId,
    ): void {
        match ($command->type) {
            CommandActionType::TerminateSession => $this->terminate($command, $contact, $assistantId),
            CommandActionType::StartFlow        => $this->startFlow($command, $contact, $assistantId),
            CommandActionType::SendMessage      => $this->sendInformational($command, $contact, $assistantId),
        };
    }

    private function terminate(ResolvedCommand $command, Contact $contact, string $assistantId): void
    {
        $session = $this->sessions->findActiveForContact($contact, $assistantId);

        if ($session instanceof FlowSession) {
            $this->markTerminated($session);
        }

        $message = $this->resolveResponse($command, $contact);

        if (null !== $message) {
            $this->messenger->send($contact, $assistantId, $message);
        }
    }

    private function startFlow(ResolvedCommand $command, Contact $contact, string $assistantId): void
    {
        $flowId = $command->flowId;

        if (null === $flowId) {
            return;
        }

        $existing = $this->sessions->findActiveForContact($contact, $assistantId);
        if ($existing instanceof FlowSession) {
            $this->markTerminated($existing);
        }

        $definition = $this->definitions->findLatestActiveByFlowId($flowId);

        if (null === $definition) {
            return;
        }

        $this->engine->start($definition, $contact);

        // Optional ack message (commands typed by the user usually don't ack
        // before the flow's first send_message — but tenants can configure one).
        $message = $this->resolveResponse($command, $contact);

        if (null !== $message) {
            $this->messenger->send($contact, $assistantId, $message);
        }
    }

    private function sendInformational(ResolvedCommand $command, Contact $contact, string $assistantId): void
    {
        $text = $this->resolveText($command, $contact) ?? $this->resolveResponse($command, $contact);

        if (null !== $text) {
            $this->messenger->send($contact, $assistantId, $text);
        }
    }

    /**
     * Built-in commands carry a translation key (responseKey); tenant-defined
     * commands carry either a literal string (legacy) or a `lang => text`
     * locale map (after the localization migration). Both shapes are
     * resolved through ContentTranslator. No active session is required
     * (commands run before lock acquisition) so we don't consult session.state.
     */
    private function resolveResponse(ResolvedCommand $command, Contact $contact): ?string
    {
        $language = $this->resolveLanguage($contact);

        if (null !== $command->responseKey) {
            return $this->translator->translate($command->responseKey, $language);
        }

        return $this->resolveLocalized($command->response, $language);
    }

    private function resolveText(ResolvedCommand $command, Contact $contact): ?string
    {
        return $this->resolveLocalized($command->text, $this->resolveLanguage($contact));
    }

    /**
     * @param  string|array<string, string>|null  $field
     */
    private function resolveLocalized(string|array|null $field, string $language): ?string
    {
        if (null === $field) {
            return null;
        }

        $resolved = $this->translator->resolveField($field, $language);

        return '' === $resolved ? null : $resolved;
    }

    private function resolveLanguage(Contact $contact): string
    {
        if (is_string($contact->language) && '' !== $contact->language) {
            return $contact->language;
        }

        return $this->tenantSettings->fallback_language;
    }

    /**
     * Force-terminate a session bypassing optimistic lock — global commands
     * are explicitly permitted to break in even when a worker is stuck.
     */
    private function markTerminated(FlowSession $session): void
    {
        $session->newQuery()
            ->whereKey($session->getKey())
            ->update([
                'status'          => FlowSessionStatus::TerminatedByUser->value,
                'current_node_id' => null,
                'updated_at'      => now(),
            ]);
    }
}
