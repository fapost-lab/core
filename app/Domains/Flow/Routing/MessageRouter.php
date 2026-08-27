<?php

declare(strict_types=1);

namespace App\Domains\Flow\Routing;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Contracts\ConversationOwnershipInterface;
use App\Domains\Conversation\DTO\ConversationRef;
use App\Domains\Flow\Commands\CommandMatcher;
use App\Domains\Flow\Commands\GlobalCommandExecutorInterface;
use App\Domains\Flow\Concurrency\LockAcquisitionPolicy;
use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Concurrency\SessionLockManager;
use App\Domains\Flow\Concurrency\SessionLockRegistry;
use App\Domains\Flow\Contracts\FlowOrchestratorInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Exceptions\SessionLockLostException;
use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use App\Domains\Messaging\Typing\TypingHeartbeatRegistry;
use App\Domains\Messaging\Typing\TypingIndicatorService;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\Flow\Contracts\TriggerResolverInterface;
use FAPost\Foundation\Flow\DTO\TriggerContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Coordinates the 6-step message routing pipeline (ADR Message Routing &
 * Concurrency Control § "Message Routing Pipeline"):
 *
 *   1. Global command match (pre-lock, synchronous)
 *   2. Typing indicator start
 *   3. Lock acquisition with backoff retry
 *   4. Session state classification → drop / route / start
 *   5. Flow execution via {@see FlowOrchestratorInterface}
 *   6. Cleanup (stop typing, release lock)
 *
 * The lock claimed in step 3 is the single session lock for the whole run:
 * it is published to {@see SessionLockRegistry} so the execution guard stays
 * re-entrant and the engine can heartbeat it between nodes.
 *
 * The router is transport-agnostic: webhook ingress jobs invoke {@see route()}
 * after switching tenant context and resolving Contact / Assistant / Channel.
 *
 * Pipeline result is reported via {@see RoutingOutcome} so callers (jobs)
 * can apply transport-specific retry semantics for {@code lock_busy} —
 * the router itself never re-queues.
 */
final readonly class MessageRouter
{
    public function __construct(
        private CommandMatcher $commandMatcher,
        private GlobalCommandExecutorInterface $commandExecutor,
        private TypingIndicatorService $typing,
        private SessionStateRouter $stateRouter,
        private DropPolicyInterface $dropPolicy,
        private FlowSessionRepositoryInterface $sessions,
        private TriggerResolverInterface $triggerResolver,
        private FlowOrchestratorInterface $orchestrator,
        private LockAcquisitionPolicy $lockPolicy,
        private SessionLockManager $lockManager,
        private SessionLockRegistry $lockRegistry,
        private ConversationOwnershipInterface $ownership,
        private TypingHeartbeatRegistry $typingHeartbeat,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function route(
        Contact $contact,
        IncomingMessage $message,
        Assistant $assistant,
        Channel $channel,
    ): RoutingOutcome {
        $assistantId = (string)$assistant->getKey();

        // Step 1: synchronous global command match (no lock).
        if (null !== $message->text) {
            $resolved = $this->commandMatcher->match($message->text, $assistant);

            if (null !== $resolved) {
                $this->commandExecutor->execute($resolved, $contact, $assistantId);

                return RoutingOutcome::commandHandled($resolved->command);
            }
        }

        // Step 2: best-effort typing indicator. Hand it off to the per-request
        // {@see TypingHeartbeatRegistry} so the engine can refresh it before
        // each handler.execute() and the indicator survives multi-node runs.
        $typingSession = $this->typing->start(
            channelType: $channel->type,
            chatId: $message->externalChatId,
            transportToken: (string)$channel->token,
        );

        if (null !== $typingSession) {
            $this->typingHeartbeat->set($typingSession);
        }

        try {
            // Step 3: lock acquisition with backoff retry. Scope is the
            // (tenant, contact, assistant) triple from ADR § "Distributed Lock
            // Strategy" — the same scope the execution guard uses further down,
            // so the whole pipeline holds exactly one lock.
            $scope = new LockScope(
                tenantId: (string)$contact->tenant_id,
                contactId: (string)$contact->getKey(),
                assistantId: $assistantId,
            );
            $handle = $this->lockPolicy->acquireWithRetry($scope);

            if (null === $handle) {
                $this->dropPolicy->applyBusy($contact, $assistant);

                return RoutingOutcome::dropped('lock_timeout');
            }

            // Hand the claim to the per-request registry: the execution guard
            // reads it to stay re-entrant, the engine reads it to heartbeat.
            $this->lockRegistry->set($handle);

            try {
                // Step 4a: an operator holding this thread outranks the flow
                // engine. Checked before session state because the session may
                // still be sitting in waiting_input from before the takeover —
                // classifying first would resume a flow the operator replaced.
                // The message is already in the transcript (IncomingMessageJob
                // captures before routing), so nothing is lost by stopping here.
                if ($this->ownership->isHandledByStaff($this->conversationRef($contact, $assistant, $channel, $message))) {
                    return RoutingOutcome::dropped('staff_handled');
                }

                // Step 4b: classify by session state.
                $session  = $this->sessions->findActiveForContact($contact, $assistantId);
                $decision = $this->stateRouter->decide($session);

                if (SessionRoutingDecision::DropBusy === $decision) {
                    $this->dropPolicy->applyBusy($contact, $assistant);

                    return RoutingOutcome::dropped('drop_busy');
                }

                if (SessionRoutingDecision::DropSilent === $decision) {
                    $this->dropPolicy->applySilent($contact, $assistant);

                    return RoutingOutcome::dropped('drop_silent');
                }

                // Step 5: execute. Trigger resolved only when starting fresh.
                $trigger = SessionRoutingDecision::StartViaTrigger === $decision
                    ? $this->resolveMessageTrigger($message, $assistantId, (string)$contact->tenant_id)
                    : null;

                try {
                    $this->orchestrator->handle($contact, $message, $assistantId, $trigger);
                } catch (SessionLockTimeoutException $exception) {
                    $this->logger->warning('messaging.routing.engine_lock_timeout', [
                        'tenant_id'    => (string)$contact->tenant_id,
                        'contact_id'   => (string)$contact->getKey(),
                        'assistant_id' => $assistantId,
                        'error'        => $exception->getMessage(),
                    ]);

                    return RoutingOutcome::dropped('engine_lock_timeout');
                } catch (SessionLockLostException $exception) {
                    // Heartbeat found the claim taken over mid-execution. The
                    // engine already abandoned the run; nothing to retry here,
                    // the new owner is processing this contact.
                    $this->logger->warning('messaging.routing.lock_lost', [
                        'tenant_id'    => (string)$contact->tenant_id,
                        'contact_id'   => (string)$contact->getKey(),
                        'assistant_id' => $assistantId,
                        'error'        => $exception->getMessage(),
                    ]);

                    return RoutingOutcome::dropped('lock_lost');
                }

                return RoutingOutcome::executed($decision);
            } finally {
                // Step 6a: release lock (token-checked — a drifted claim is a
                // silent no-op rather than stealing the new owner's lock).
                $this->lockRegistry->clear();

                try {
                    $this->lockManager->release($handle);
                } catch (Throwable $exception) {
                    $this->logger->debug('messaging.routing.lock_release_failed', [
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        } finally {
            // Step 6b: stop typing + clear the heartbeat slot.
            $this->typingHeartbeat->clear();
            $typingSession?->stop();
            unset($typingSession);
        }
    }

    private function conversationRef(
        Contact $contact,
        Assistant $assistant,
        Channel $channel,
        IncomingMessage $message,
    ): ConversationRef {
        return new ConversationRef(
            tenantId: (string)$contact->tenant_id,
            assistantId: (string)$assistant->getKey(),
            contactId: (string)$contact->getKey(),
            channelId: (string)$channel->getKey(),
            platform: $message->platform,
        );
    }

    private function resolveMessageTrigger(IncomingMessage $message, string $assistantId, string $tenantId): mixed
    {
        return $this->triggerResolver->resolve(
            new TriggerContext(
                type: FlowTriggerType::Message->value,
                tenantId: $tenantId,
                assistantId: $assistantId,
                payload: [
                    'text'             => $message->text,
                    'incoming_payload' => $message->payload,
                ],
            )
        );
    }
}
