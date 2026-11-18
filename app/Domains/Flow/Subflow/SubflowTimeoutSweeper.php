<?php

declare(strict_types=1);

namespace App\Domains\Flow\Subflow;

use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\OptimisticLockConflictException;
use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use App\Domains\Flow\Models\FlowSession;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Per-tenant maintenance sweep for expired subflow chains. Runs on a cron
 * schedule (default every minute) and:
 *
 *  • For each parent in {@code paused_subflow} whose {@code expires_at} has
 *    passed (deepest sessions first, so a nested chain unwinds bottom-up):
 *    force-end any still-living child (running, waiting, asleep in a `delay`
 *    or itself paused on a nested subflow) as {@code failed} and let
 *    {@see SubflowResumerInterface} route the parent through its
 *    {@code failed} handle.
 *
 *  • For each parent in {@code paused_subflow} with no live child — a child is live while it is
 *    active, waiting for input, paused on a delay or paused on a nested subflow (orphan):
 *    mark the parent as {@code expired}. Should not normally happen because
 *    {@see SubflowStarterService} extends parent expiry past child + buffer,
 *    but we recover gracefully if the invariant is ever broken.
 *
 * Each parent is handled under its session lock ({@see FlowExecutionGuardInterface}),
 * the one an inbound message for the same contact takes, and re-read under it:
 * the child may have been answered, or the parent resumed, since the query. A
 * parent whose lock is busy is skipped and logged; the next sweep retries it.
 *
 * Tenant context is owned by the caller (typically a console command iterating
 * over active tenants); the sweeper runs queries against the current
 * connection only.
 */
final readonly class SubflowTimeoutSweeper
{
    /** Subflow chains are at most 3 deep (ADR-11); the bound only guards against a corrupt parent loop. */
    private const int MAX_CHAIN_DEPTH = 5;

    public function __construct(
        private ConnectionInterface $connection,
        private SubflowResumerInterface $resumer,
        private FlowExecutionGuardInterface $guard,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Sweep expired subflow chains for the current tenant connection.
     *
     * @return array{forced_failures: int, orphans_expired: int}
     */
    public function sweep(): array
    {
        $now            = now();
        $forcedFailures = 0;
        $orphansExpired = 0;

        $expiredParents = FlowSession::query()
            ->where('status', FlowSessionStatus::PausedSubflow->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->orderByDesc('created_at')
            ->get();

        foreach ($expiredParents as $parent) {
            try {
                $outcome = $this->guard->run(
                    tenantId: (string) $parent->tenant_id,
                    contactId: (string) $parent->contact_id,
                    assistantId: (string) $parent->assistant_id,
                    callback: fn (): ?string => $this->sweepParent($parent, $now),
                );
            } catch (SessionLockTimeoutException) {
                $this->logger->info('flow.subflow.timeout.lock_busy', [
                    'parent_id' => (string) $parent->getKey(),
                ]);

                continue;
            }

            if ('failed' === $outcome) {
                ++$forcedFailures;
            } elseif ('expired' === $outcome) {
                ++$orphansExpired;
            }
        }

        return [
            'forced_failures' => $forcedFailures,
            'orphans_expired' => $orphansExpired,
        ];
    }

    /**
     * Handle one expired parent while holding its session lock.
     *
     * @return 'failed'|'expired'|null what was done, or null when the parent no longer needs sweeping
     */
    private function sweepParent(FlowSession $parent, DateTimeInterface $now): ?string
    {
        try {
            $parent->refresh();
        } catch (ModelNotFoundException) {
            return null; // deleted since the unlocked query
        }

        if (FlowSessionStatus::PausedSubflow !== $parent->status
            || null === $parent->expires_at
            || $parent->expires_at >= $now) {
            return null;
        }

        $liveChild = FlowSession::query()
            ->where('parent_session_id', $parent->getKey())
            ->whereIn('status', [
                FlowSessionStatus::Active->value,
                FlowSessionStatus::WaitingInput->value,
                // A child sleeping in a `delay` node, or itself waiting on a nested subflow, is alive: its
                // own expiry (or its child's) decides, not this parent's.
                FlowSessionStatus::Paused->value,
                FlowSessionStatus::PausedSubflow->value,
            ])
            ->latest('created_at')
            ->first();

        if (null === $liveChild) {
            $this->markExpired($parent);

            // An orphan that is itself a child must not leave its own parent waiting for it.
            if (null !== $parent->parent_session_id && '' !== $parent->parent_session_id) {
                try {
                    $this->resumer->resumeIfChild($parent, EndStatus::Failed->value);
                } catch (Throwable $exception) {
                    $this->logger->warning('flow.subflow.timeout.orphan_hand_back_failed', [
                        'parent_id' => (string) $parent->getKey(),
                        'error'     => $exception->getMessage(),
                    ]);
                }
            }

            return 'expired';
        }

        try {
            $this->failLiveDescendants($liveChild);
            $this->forceFailChild($liveChild);
            $this->resumer->resumeIfChild($liveChild, EndStatus::Failed->value);

            return 'failed';
        } catch (Throwable $exception) {
            $this->logger->warning('flow.subflow.timeout.sweep_failed', [
                'parent_id' => (string) $parent->getKey(),
                'child_id'  => (string) $liveChild->getKey(),
                'error'     => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * A child that is itself paused on a nested subflow takes its own live children with it: left running
     * they would later "return" into a parent that has already moved on. The expiry bounds the whole chain.
     */
    private function failLiveDescendants(FlowSession $session, int $depth = 0): void
    {
        if (FlowSessionStatus::PausedSubflow !== $session->status || $depth >= self::MAX_CHAIN_DEPTH) {
            return;
        }

        $descendants = FlowSession::query()
            ->where('parent_session_id', $session->getKey())
            ->whereIn('status', [
                FlowSessionStatus::Active->value,
                FlowSessionStatus::WaitingInput->value,
                FlowSessionStatus::Paused->value,
                FlowSessionStatus::PausedSubflow->value,
            ])
            ->get();

        foreach ($descendants as $descendant) {
            $this->failLiveDescendants($descendant, $depth + 1);
            $this->forceFailChild($descendant);
        }
    }

    private function forceFailChild(FlowSession $child): void
    {
        $this->connection->transaction(function () use ($child): void {
            try {
                $child->saveWithOptimisticLock([
                    'status'          => FlowSessionStatus::Ended,
                    'end_status'      => EndStatus::Failed->value,
                    'current_node_id' => null,
                ]);
            } catch (OptimisticLockConflictException $exception) {
                // Another worker already advanced the child. Refresh and skip — the
                // resume hook will fire from whoever wins; here we simply log.
                $this->logger->info('flow.subflow.timeout.child_lock_lost', [
                    'child_id' => (string) $child->getKey(),
                    'error'    => $exception->getMessage(),
                ]);
            }
        });
    }

    private function markExpired(FlowSession $parent): void
    {
        $this->connection->transaction(function () use ($parent): void {
            try {
                $parent->saveWithOptimisticLock([
                    'status'          => FlowSessionStatus::Expired,
                    'current_node_id' => null,
                ]);
            } catch (OptimisticLockConflictException $exception) {
                $this->logger->info('flow.subflow.timeout.parent_lock_lost', [
                    'parent_id' => (string) $parent->getKey(),
                    'error'     => $exception->getMessage(),
                ]);
            }
        });
    }
}
