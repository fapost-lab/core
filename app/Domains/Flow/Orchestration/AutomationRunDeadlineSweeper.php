<?php

declare(strict_types=1);

namespace App\Domains\Flow\Orchestration;

use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Contracts\AfterCommitDispatcherInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Enums\FlowRunFailureReason;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\OptimisticLockConflictException;
use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Retention\AutomationRunRetention;
use App\Domains\Flow\Services\SegmentHandOff;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Fapost\Foundation\Analytics\Contracts\AnalyticsWriterInterface;
use Fapost\Foundation\Analytics\DTO\AnalyticsEvent;
use Fapost\Foundation\Analytics\Enums\AnalyticsEventType;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fails the automation runs of one tenant that are still live past their deadline (adr-20261119-automation-run-deadline).
 * Run every minute by `flow:sweep-overdue-automation-runs`, so a run whose job was lost — a push that never reached
 * the queue, a worker killed mid-segment, a sweep that broke off — never keeps its trigger and its flow for good.
 *
 * A run is overdue when its deadline ({@see AutomationRunDeadline}) has passed — measured from `created_at` for a row
 * without one — or when it is still `pending` past {@see AutomationRunDeadline::PENDING_LOST_AFTER_SECONDS}. Then every
 * live session of the run, root and subflow children alike, becomes `failed` with a `failure_reason`, bottom-up and
 * in one transaction; the root's `segment_id` is cleared, so any continuation still queued for it is stale. No node
 * runs and no parent is routed through its `failed` handle: the deadline bounds the whole run, not one of its steps.
 * Conversations are never touched.
 *
 * Each run is handled under its root's session lock ({@see LockScope::ofSession()}), the one its segments take, and
 * re-read under it. A busy lock means a segment is running: the run is skipped and the next pass retries it. A
 * conflicting write rolls the whole run back for the next pass, and a run that cannot be handled at all is logged and
 * skipped; neither ends the pass.
 *
 * Tenant context is owned by the caller; the tenant is passed explicitly as well, and every query is filtered by it.
 */
final readonly class AutomationRunDeadlineSweeper
{
    public function __construct(
        private ConnectionInterface $connection,
        private FlowExecutionGuardInterface $guard,
        private SegmentHandOff $handOff,
        private AutomationRunDeadline $deadline,
        private AnalyticsWriterInterface $analyticsWriter,
        private AfterCommitDispatcherInterface $afterCommitDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Fail every overdue automation run of {@code $tenantId} in the current tenant connection.
     *
     * @return array{failed_runs: int, failed_sessions: int, skipped_busy: int}
     */
    public function sweep(string $tenantId): array
    {
        $now            = now()->toImmutable();
        $failedRuns     = 0;
        $failedSessions = 0;
        $skippedBusy    = 0;

        foreach ($this->overdueRunTops($tenantId, $now) as $top) {
            try {
                $failed = $this->guard->runInScope(
                    LockScope::ofSession($top),
                    fn (): int => $this->failIfOverdue($tenantId, (string) $top->getKey(), $now),
                );
            } catch (SessionLockTimeoutException) {
                $this->logger->info('flow.automation.run_overdue_lock_busy', [
                    'root_id' => (string) $top->getKey(),
                ]);
                ++$skippedBusy;

                continue;
            } catch (OptimisticLockConflictException $exception) {
                $this->logger->info('flow.automation.run_overdue_conflict', [
                    'root_id' => (string) $top->getKey(),
                    'error'   => $exception->getMessage(),
                ]);

                continue;
            } catch (Throwable $exception) {
                // One run never ends the pass: whatever breaks for it must not leave every later run of the tenant
                // overdue. A run without a lock scope (contactless and without a trigger) lands here.
                $this->logger->warning('flow.automation.run_overdue_skipped', [
                    'root_id' => (string) $top->getKey(),
                    'error'   => $exception->getMessage(),
                ]);

                continue;
            }

            if ($failed > 0) {
                ++$failedRuns;
                $failedSessions += $failed;
            }
        }

        return [
            'failed_runs'     => $failedRuns,
            'failed_sessions' => $failedSessions,
            'skipped_busy'    => $skippedBusy,
        ];
    }

    /**
     * The top session of every run with an overdue live session, each once: the run's root, or — when the root row is
     * gone — the highest session of the chain that is left. Read without a lock; {@see failIfOverdue()} checks again.
     *
     * @return list<FlowSession>
     */
    private function overdueRunTops(string $tenantId, CarbonImmutable $now): array
    {
        $candidates = $this->deadline
            ->whereOverdue($this->liveAutomationSessions($tenantId), $now)
            ->orderBy('created_at')
            ->get();

        $tops = [];

        foreach ($candidates as $candidate) {
            ['root_id' => $rootId, 'top' => $top] = $this->handOff->chainOf($candidate);

            $tops[$rootId] ??= $top;
        }

        return array_values($tops);
    }

    /**
     * Under the run's lock: re-read the run, and fail every live session of it when it is still overdue.
     *
     * @return int the sessions failed; 0 when the run is no longer overdue, or gone
     */
    private function failIfOverdue(string $tenantId, string $topId, CarbonImmutable $now): int
    {
        $top = FlowSession::query()->find($topId);

        if (null === $top) {
            return 0; // deleted since the unlocked query
        }

        $live = $this->liveSessionsOfRun($tenantId, $top);

        if ([] === $live || ! $this->anyOverdue($live, $now)) {
            return 0;
        }

        $reason = FlowSessionStatus::Pending === $top->status
            ? FlowRunFailureReason::StartLost
            : FlowRunFailureReason::DeadlineExceeded;

        $this->connection->transaction(function () use ($live, $top, $reason): void {
            foreach ($live as $session) {
                $attributes = [
                    'status'         => FlowSessionStatus::Failed,
                    'failure_reason' => $reason,
                ];

                if ((string) $session->getKey() === (string) $top->getKey()) {
                    $attributes['segment_id'] = null;
                }

                $session->saveWithOptimisticLock($attributes);
            }

            $this->afterCommitDispatcher->afterCommit(function () use ($live, $reason): void {
                foreach ($live as $session) {
                    $this->analyticsWriter->record(new AnalyticsEvent(
                        tenantId: (string) $session->tenant_id,
                        eventType: AnalyticsEventType::FlowFailed,
                        payload: ['session_id' => (string) $session->getKey(), 'reason' => $reason->value],
                        occurredAt: new DateTimeImmutable(),
                    ));
                }
            });
        });

        $this->logger->warning('flow.automation.run_overdue_failed', [
            'root_id'     => (string) $top->getKey(),
            'trigger_id'  => $top->trigger_id,
            'reason'      => $reason->value,
            'deadline_at' => $this->deadline->effectiveFor($top)?->toIso8601String(),
            'sessions'    => count($live),
        ]);

        return count($live);
    }

    /**
     * The live automation sessions of the tenant's run under {@code $top} — itself and every session below it by
     * `parent_session_id` — deepest first, so a run unwinds bottom-up.
     *
     * @return list<FlowSession>
     */
    private function liveSessionsOfRun(string $tenantId, FlowSession $top): array
    {
        $levels    = [[$top]];
        $parentIds = [(string) $top->getKey()];

        for ($depth = 1; $depth < SegmentHandOff::MAX_CHAIN_DEPTH && [] !== $parentIds; ++$depth) {
            $children = FlowSession::query()->whereIn('parent_session_id', $parentIds)->get()->all();

            if ([] === $children) {
                break;
            }

            $levels[]  = $children;
            $parentIds = array_map(static fn (FlowSession $child): string => (string) $child->getKey(), $children);
        }

        $live = [];

        foreach (array_reverse($levels) as $level) {
            foreach ($level as $session) {
                if ($this->isLiveAutomationOf($tenantId, $session)) {
                    $live[] = $session;
                }
            }
        }

        return $live;
    }

    /**
     * @param  list<FlowSession>  $sessions
     */
    private function anyOverdue(array $sessions, CarbonImmutable $now): bool
    {
        foreach ($sessions as $session) {
            if ($this->deadline->isOverdue($session, $now)) {
                return true;
            }
        }

        return false;
    }

    private function isLiveAutomationOf(string $tenantId, FlowSession $session): bool
    {
        return FlowRunKind::Automation === $session->run_kind
            && $tenantId === (string) $session->tenant_id
            && in_array($session->status, AutomationRunRetention::LIVE, true);
    }

    /**
     * @return Builder<FlowSession>
     */
    private function liveAutomationSessions(string $tenantId): Builder
    {
        return FlowSession::query()
            ->where('tenant_id', $tenantId)
            ->where('run_kind', FlowRunKind::Automation->value)
            ->whereIn('status', array_map(
                static fn (FlowSessionStatus $status): string => $status->value,
                AutomationRunRetention::LIVE,
            ));
    }
}
