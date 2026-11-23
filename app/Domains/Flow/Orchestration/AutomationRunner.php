<?php

declare(strict_types=1);

namespace App\Domains\Flow\Orchestration;

use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\DTOs\FlowRunOrigin;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\SegmentHandOff;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * Starts and continues contactless automation runs, idempotently.
 *
 * A start names the trigger and what fired it (the fire key: a schedule slot, an event id). Under the trigger's
 * lock it looks the run up by `(trigger_id, fire_key)`: a run already there is continued when it is still `active`
 * — the job that started it was killed or retried — started when it is `pending` (a schedule creates its run when it
 * claims the slot, {@see \App\Domains\Flow\Schedule\ScheduleTriggerFirer}), and left alone otherwise; only a fire
 * with no run starts one.
 * The session row is committed before its first node runs, so a job killed at any point leaves a row its retry
 * continues rather than a second run.
 *
 * A run is its root session and the subflow children under it; at most one session of the chain is `active` at a
 * time (a parent waits in `paused_subflow` while its child runs, a child has ended once its parent resumes). The
 * segment id lives on the root. A continuation runs the next segment of a run that stopped at its saved position
 * ({@see \App\Domains\Flow\Services\ExecutionSegment}) only while the root still carries the segment id the
 * continuation carries, and then runs whichever session of the run is `active` — so a retry of a job killed inside
 * a nested call (a child running inside its parent's step, a parent resumed by its child's end) still continues the
 * run. A stale or duplicate continuation is a no-op, so at most one continuation of a run is ever valid.
 */
final readonly class AutomationRunner
{
    /** The queue of automation runs: their starts and their segments, apart from conversations and `sync.external`. */
    public const string QUEUE = 'flow.automations';

    /**
     * How long the job that starts a run keeps retrying on a busy trigger lock, beside the one postponement of a
     * stopped tenant. A `pending` run older than that window and more has lost its start
     * ({@see AutomationRunDeadline::PENDING_LOST_AFTER_SECONDS}).
     */
    public const int START_RETRY_SECONDS = 3600;

    public function __construct(
        private FlowSessionRepositoryInterface $sessions,
        private FlowDefinitionRepositoryInterface $definitions,
        private FlowExecutionGuardInterface $guard,
        private FlowEngineInterface $engine,
        private SegmentHandOff $handOff,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Start the run a trigger fired, once per fire key, or continue it if a previous attempt already started it.
     *
     * @param  array<string, mixed>  $initialState  merged into the new session's state
     * @param  int                   $chainDepth    the depth of the event that fired the trigger; 0 when none did
     *
     * @return FlowSession|null the run, or null when the flow has no active definition
     *
     * @throws \App\Domains\Flow\Exceptions\SessionLockTimeoutException when another run of the trigger holds the lock
     */
    public function start(
        string $tenantId,
        string $flowId,
        string $triggerId,
        string $fireKey,
        array $initialState = [],
        ?string $assistantId = null,
        int $chainDepth = 0,
    ): ?FlowSession {
        return $this->guard->runInScope(
            LockScope::forTrigger($tenantId, $triggerId),
            function () use ($flowId, $triggerId, $fireKey, $initialState, $assistantId, $chainDepth): ?FlowSession {
                $fired = $this->sessions->findByFireKey($triggerId, $fireKey);

                if (null !== $fired) {
                    return $this->continueFired($fired);
                }

                $definition = $this->definitions->findLatestActiveByFlowId($flowId);

                if (null === $definition) {
                    $this->logger->warning('flow.automation.start_skipped_no_active_flow', [
                        'flow_id'    => $flowId,
                        'trigger_id' => $triggerId,
                        'fire_key'   => $fireKey,
                    ]);

                    return null;
                }

                try {
                    return $this->engine->start(
                        $definition,
                        null,
                        $initialState,
                        FlowRunOrigin::automation($triggerId, $assistantId, $fireKey, $chainDepth),
                    );
                } catch (UniqueConstraintViolationException $exception) {
                    // Another worker created the run past the lock (its TTL lapsed): continue that one.
                    $fired = $this->sessions->findByFireKey($triggerId, $fireKey);

                    if (null === $fired) {
                        throw $exception;
                    }

                    return $this->continueFired($fired);
                }
            },
        );
    }

    /**
     * Run the next segment of the run rooted at {@code $rootSessionId}, if {@code $segmentId} is still the one the
     * root waits for.
     *
     * @throws \App\Domains\Flow\Exceptions\SessionLockTimeoutException when the run's lock stays busy
     */
    public function continue(string $rootSessionId, string $segmentId): void
    {
        $root = $this->sessions->findById($rootSessionId);

        if (null === $root) {
            return;
        }

        $this->guard->runInScope(
            LockScope::ofSession($root),
            function () use ($root, $segmentId): void {
                $root->refresh();

                if ($segmentId !== $root->segment_id) {
                    $this->logger->debug('flow.automation.continuation_stale', [
                        'session_id' => (string) $root->getKey(),
                        'segment_id' => $segmentId,
                    ]);

                    return;
                }

                $live = $this->handOff->liveSessionOf($root);

                if (null !== $live) {
                    $this->engine->runSession($live);
                }
            },
        );
    }

    /**
     * A fire that already has a run: a `pending` run (created when the trigger claimed its fire, as a schedule does)
     * becomes `active` and runs; an `active` session is continued from its saved position; a run that is parked, ended
     * or failed is not started again.
     */
    private function continueFired(FlowSession $root): FlowSession
    {
        if (FlowSessionStatus::Pending === $root->status) {
            $this->engine->activate($root);
        }

        $live = $this->handOff->liveSessionOf($root);

        if (null !== $live) {
            $this->engine->runSession($live);
        }

        return $root->refresh();
    }
}
