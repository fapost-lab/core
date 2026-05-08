<?php

declare(strict_types=1);

namespace App\Domains\Flow\Routing;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Commands\CommandMatcher;
use App\Domains\Flow\Commands\GlobalCommandExecutorInterface;
use App\Domains\Flow\Contracts\FlowOrchestratorInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use App\Domains\Messaging\Typing\TypingHeartbeatRegistry;
use App\Domains\Messaging\Typing\TypingIndicatorService;
use App\Domains\Messaging\Typing\TypingSession;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\Flow\Contracts\TriggerResolverInterface;
use FAPost\Foundation\Flow\DTO\TriggerContext;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
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
 * The router is transport-agnostic: webhook ingress jobs invoke {@see route()}
 * after switching tenant context and resolving Contact / Assistant / Channel.
 *
 * Pipeline result is reported via {@see RoutingOutcome} so callers (jobs)
 * can apply transport-specific retry semantics for {@code lock_busy} —
 * the router itself never re-queues.
 */
final readonly class MessageRouter
{
    private const int LOCK_TTL_SECONDS         = 30;
    private const int LOCK_ACQUISITION_RETRIES = 3;
    private const int LOCK_RETRY_DELAY_SECONDS = 2;

    public function __construct(
        private CommandMatcher $commandMatcher,
        private GlobalCommandExecutorInterface $commandExecutor,
        private TypingIndicatorService $typing,
        private SessionStateRouter $stateRouter,
        private DropPolicyInterface $dropPolicy,
        private FlowSessionRepositoryInterface $sessions,
        private TriggerResolverInterface $triggerResolver,
        private FlowOrchestratorInterface $orchestrator,
        private CacheRepository $cache,
        private TypingHeartbeatRegistry $typingHeartbeat,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Per-tick callback to refresh both the lock TTL and the typing
     * indicator. Intended for the engine's heartbeat hook (Phase A-5).
     * Currently exposed but not yet wired (engine still owns lock).
     */
    public static function tickHeartbeat(?TypingSession $typing): void
    {
        $typing?->refresh();
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
            // Step 3: lock acquisition with backoff retry.
            $lockKey  = $this->buildLockKey($contact, $assistant, $message);
            $lock     = $this->cache->lock($lockKey, self::LOCK_TTL_SECONDS);
            $acquired = false;

            for ($attempt = 0; $attempt < self::LOCK_ACQUISITION_RETRIES; ++$attempt) {
                if ($lock->get()) {
                    $acquired = true;
                    break;
                }
                if ($attempt < self::LOCK_ACQUISITION_RETRIES - 1) {
                    sleep(self::LOCK_RETRY_DELAY_SECONDS);
                }
            }

            if ( ! $acquired) {
                $this->dropPolicy->applyBusy($contact, $assistant);

                return RoutingOutcome::dropped('lock_timeout');
            }

            try {
                // Step 4: classify by session state.
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
                }

                return RoutingOutcome::executed($decision);
            } finally {
                // Step 6a: release lock.
                try {
                    $lock->release();
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

    private function buildLockKey(Contact $contact, Assistant $assistant, IncomingMessage $message): string
    {
        return sprintf(
            'session_lock:%s:%s:%s:%s',
            (string)$contact->tenant_id,
            $message->platform,
            $message->externalUserId,
            (string)$assistant->getKey(),
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
