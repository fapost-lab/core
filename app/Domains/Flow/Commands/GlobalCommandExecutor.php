<?php

declare(strict_types=1);

namespace App\Domains\Flow\Commands;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\FallbackMessageServiceInterface;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Contracts\SolutionAvailabilityInterface;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Routing\DropPolicy;
use App\Domains\Flow\Routing\DropPolicyInterface;
use App\Domains\Flow\Services\FlowSessionTerminator;
use App\Domains\Tenancy\Settings\TenantSettings;
use Fapost\Foundation\Flow\Commands\CommandContext;
use Fapost\Foundation\Flow\Commands\CommandOutcome;
use Fapost\Foundation\Flow\Commands\CommandOutcomeKind;
use Psr\Log\LoggerInterface;
use Throwable;

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
 *
 * A session a command ends takes its pending operator request with it: the
 * thread goes back to the bot instead of waiting for an operator forever (the
 * bot stays silent while it waits). A thread an operator already handles is
 * left to them. The parents a subflow chain left waiting in `paused_subflow` are ended with it
 * ({@see FlowSessionTerminator}).
 *
 * A command whose action is a type a Solution registered runs the type's {@see \Fapost\Foundation\Flow\Commands\CommandActionTypeInterface::handle()}
 * and carries out the {@see CommandOutcome} it returns with the same steps as the built-in types: Solution code never
 * touches the session, because a command runs before its lock. While the Solution's tables are not ready in the
 * tenant's schema the type is not run: preparation is queued and the contact gets the assistant's busy message. An
 * exception of the type is logged; the message counts as handled and the contact gets no answer.
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
        private FlowSessionTerminator $terminator,
        private CommandActionTypeRegistry $actionTypes,
        private SolutionAvailabilityInterface $availability,
        private DropPolicyInterface $dropPolicy,
        private LoggerInterface $logger,
    ) {
    }

    public function execute(
        ResolvedCommand $command,
        Contact $contact,
        string $assistantId,
        ?Assistant $assistant = null,
        ?string $channelType = null,
        ?string $updateId = null,
    ): void {
        match ($command->type) {
            CommandActionType::TerminateSession => $this->terminate($command, $contact, $assistantId),
            CommandActionType::StartFlow        => $this->startFlow($command, $contact, $assistantId),
            CommandActionType::SendMessage      => $this->sendInformational($command, $contact, $assistantId),
            null                                => $this->runRegistered($command, $contact, $assistantId, $assistant, $channelType, $updateId),
        };
    }

    private function runRegistered(
        ResolvedCommand $command,
        Contact $contact,
        string $assistantId,
        ?Assistant $assistant,
        ?string $channelType,
        ?string $updateId,
    ): void {
        $actionType = $command->actionType;

        if (null === $actionType || ! $this->actionTypes->has($actionType)) {
            return;
        }

        $owner = $this->actionTypes->ownerOf($actionType);

        if (null !== $owner && ! $this->availability->isReady($owner)) {
            $this->replyBusy($contact, $assistantId, $assistant);

            return;
        }

        try {
            $outcome = $this->actionTypes->get($actionType)->handle(new CommandContext(
                command: $command->command,
                tenantId: (string) $contact->tenant_id,
                contactId: (string) $contact->getKey(),
                assistantId: $assistantId,
                channelType: $channelType ?? '',
                language: $this->resolveLanguage($contact),
                updateId: $updateId,
            ), $command->config);
        } catch (Throwable $exception) {
            $this->logger->error('flow.command.action_type_failed', [
                'command'     => $command->command,
                'action_type' => $actionType,
                'solution'    => $owner,
                'exception'   => $exception,
            ]);

            return;
        }

        $this->carryOut($outcome, $contact, $assistantId);
    }

    private function carryOut(CommandOutcome $outcome, Contact $contact, string $assistantId): void
    {
        if (CommandOutcomeKind::Reply !== $outcome->kind) {
            $session = $this->sessions->findActiveForContact($contact, $assistantId);

            if ($session instanceof FlowSession) {
                $this->markTerminated($session);
            }
        }

        if (CommandOutcomeKind::StartFlow === $outcome->kind && null !== $outcome->flowId) {
            $definition = $this->definitions->findLatestActiveByFlowId($outcome->flowId);

            if (null !== $definition) {
                $this->engine->start($definition, $contact);
            }
        }

        if (null !== $outcome->reply) {
            $this->messenger->send($contact, $assistantId, $outcome->reply);
        }
    }

    private function replyBusy(Contact $contact, string $assistantId, ?Assistant $assistant): void
    {
        if ($assistant instanceof Assistant) {
            $this->dropPolicy->applyBusy($contact, $assistant);

            return;
        }

        $this->messenger->send($contact, $assistantId, $this->translator->translate(DropPolicy::BUSY_TRANSLATION_KEY, $this->resolveLanguage($contact)));
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
        $this->terminator->terminate($session);
    }
}
