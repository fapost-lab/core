<?php

declare(strict_types=1);

namespace App\Domains\Flow\Orchestration;

use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Retention\AutomationRunRetention;
use App\Domains\Flow\Services\SegmentHandOff;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands on the automation runs of one tenant that stranded without a job (adr-20261125-stranded-automation-run-recovery):
 * a continuation lost between the `segment_id` commit and the queue push, a job that ran out of attempts, or a parent
 * left in `paused_subflow` over a child that is already over. Run by `flow:sweep-overdue-automation-runs` right after
 * the deadline pass of the same tenant, so a run past its deadline is failed rather than recovered.
 *
 * A run is a candidate when it is `active` (or a parent waits on a child) and none of its sessions moved for the
 * platform setting {@see self::SETTING} minutes. Under the run's lock, re-read, it is handed on only when it still
 * has something to run: an `active` session, or a child's end to hand back to its parent. A busy lock means a segment
 * is running: the run is skipped. Nothing here runs a node.
 *
 * The run is handed on without moving its segment id: the continuation its root waits for is queued again
 * ({@see SegmentHandOff::redispatch()}), so one still queued behind a backlog stays valid, and of the two whichever runs
 * first continues the run while the other is stale or finds nothing to do. Only a run whose root has no segment id yet
 * (its start ran out of attempts before any segment ended) gets a new one ({@see SegmentHandOff::handOn()}). The same
 * segment of a run is queued again at most once per threshold, so a run waiting in a long backlog costs one extra job
 * per threshold, not one per pass.
 *
 * Bound `scoped`: the setting is read through the scoped settings reader. Tenant context is owned by the caller; the
 * tenant is passed explicitly as well. The candidate roots and the sessions of a run are filtered by it, and a root read
 * again under the lock is checked against it; the walks along a subflow chain ({@see SegmentHandOff::chainOf()},
 * {@see SegmentHandOff::strandedShapeOf()}) follow `parent_session_id` from a session of the tenant and are not
 * filtered again.
 */
final readonly class StrandedAutomationRunRecovery
{
    /** The platform setting with the minutes a run may stay without movement before it is handed on. */
    public const string SETTING = 'flow.automation.recover_after_minutes';

    public const int DEFAULT_MINUTES = 10;

    /**
     * A killed worker's job is back in the queue by then: `retry_after` (90 s) counts from when the job was reserved,
     * which is before the run's last write, and the job's timeout (60 s) ends it before that.
     */
    public const int MIN_MINUTES = 2;

    public const int MAX_MINUTES = 240;

    /** The statuses a session of a stranded run can be in: running, or a parent waiting on its child. */
    private const array CANDIDATE_STATUSES = [FlowSessionStatus::Active, FlowSessionStatus::PausedSubflow];

    public function __construct(
        private FlowExecutionGuardInterface $guard,
        private SegmentHandOff $handOff,
        private AutomationRunDeadline $deadline,
        private PlatformSettingsReaderInterface $platformSettings,
        private Cache $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Hand on every stranded automation run of {@code $tenantId} in the current tenant connection.
     *
     * @return array{recovered_runs: int, skipped_busy: int}
     */
    public function recover(string $tenantId): array
    {
        $now        = now()->toImmutable();
        $minutes    = $this->minutes();
        $idleBefore = $now->subMinutes($minutes);
        $recovered  = 0;
        $skipped    = 0;

        foreach ($this->idleRunRoots($tenantId, $idleBefore) as $root) {
            try {
                $handedOn = $this->guard->runInScope(
                    LockScope::ofSession($root),
                    fn (): bool => $this->handOnIfStranded($tenantId, (string) $root->getKey(), $now, $idleBefore, $minutes),
                );
            } catch (SessionLockTimeoutException) {
                ++$skipped;

                continue;
            } catch (Throwable $exception) {
                // One run never ends the pass, as in the deadline sweeper.
                $this->logger->warning('flow.automation.run_recovery_skipped', [
                    'root_id' => (string) $root->getKey(),
                    'error'   => $exception->getMessage(),
                ]);

                continue;
            }

            if ($handedOn) {
                ++$recovered;
            }
        }

        return ['recovered_runs' => $recovered, 'skipped_busy' => $skipped];
    }

    /**
     * The root of every run with an idle candidate session and something left to run, each once. A chain whose root
     * row is gone is left to the deadline: its continuations are queued for that root and can no longer run. Read
     * without a lock;
     * {@see handOnIfStranded()} checks again.
     *
     * @return list<FlowSession>
     */
    private function idleRunRoots(string $tenantId, CarbonImmutable $idleBefore): array
    {
        $candidates = FlowSession::query()
            ->where('tenant_id', $tenantId)
            ->where('run_kind', FlowRunKind::Automation->value)
            ->whereIn('status', array_map(
                static fn (FlowSessionStatus $status): string => $status->value,
                self::CANDIDATE_STATUSES,
            ))
            ->where('updated_at', '<', $idleBefore)
            ->orderBy('updated_at')
            ->get();

        $roots = [];

        foreach ($candidates as $candidate) {
            ['root_id' => $rootId, 'top' => $top] = $this->handOff->chainOf($candidate);

            if (isset($roots[$rootId]) || (string) $top->getKey() !== $rootId) {
                continue;
            }

            // A run still moving below its root (a child stepping) or parked below (a child waiting for input or in
            // a delay) has nothing to hand on: its trigger's lock, shared by every run of the trigger, is not taken
            // for it, so a segment or a start of that trigger is not held up by every pass.
            if (! $this->isIdle($this->sessionsOfRun($tenantId, $top), $idleBefore)) {
                continue;
            }

            if (null !== $this->handOff->strandedShapeOf($top)) {
                $roots[$rootId] = $top;
            }
        }

        return array_values($roots);
    }

    /**
     * Under the run's lock: re-read the run, and hand it on when it is still stranded — on time, idle in every
     * session, and with something left to run.
     */
    private function handOnIfStranded(
        string $tenantId,
        string $rootId,
        CarbonImmutable $now,
        CarbonImmutable $idleBefore,
        int $minutes,
    ): bool {
        $root = FlowSession::query()->find($rootId);

        if (null === $root || $tenantId !== (string) $root->tenant_id || FlowRunKind::Automation !== $root->run_kind) {
            return false;
        }

        $sessions = $this->sessionsOfRun($tenantId, $root);

        foreach ($sessions as $session) {
            if (in_array($session->status, AutomationRunRetention::LIVE, true) && $this->deadline->isOverdue($session, $now)) {
                return false; // the deadline's to fail, not ours to recover
            }
        }

        $lastMoveAt = $this->lastMoveOf($sessions);

        if (null === $lastMoveAt || ! $lastMoveAt->lt($idleBefore)) {
            return false;
        }

        $shape = $this->handOff->strandedShapeOf($root);

        if (null === $shape) {
            return false;
        }

        $segmentId = is_string($root->segment_id) && '' !== $root->segment_id ? $root->segment_id : null;
        $mark      = sprintf('flow:run-recovery:%s:%s:%s', $tenantId, $rootId, $segmentId ?? 'start');

        if (! $this->cache->add($mark, true, $minutes * 60)) {
            return false; // queued again for this segment within the threshold already
        }

        if (null !== $segmentId) {
            $this->handOff->redispatch($root);
        } else {
            $this->handOff->handOn($root);
        }

        $this->logger->info('flow.automation.run_recovered', [
            'root_id'      => $rootId,
            'trigger_id'   => $root->trigger_id,
            'segment_id'   => $segmentId,
            'shape'        => $shape,
            'idle_seconds' => (int) $lastMoveAt->diffInSeconds($now, true),
        ]);

        return true;
    }

    /**
     * @param  list<FlowSession>  $sessions
     */
    private function isIdle(array $sessions, CarbonImmutable $idleBefore): bool
    {
        $lastMoveAt = $this->lastMoveOf($sessions);

        return null !== $lastMoveAt && $lastMoveAt->lt($idleBefore);
    }

    /**
     * When the run last moved: the latest `updated_at` over its sessions.
     *
     * @param  list<FlowSession>  $sessions
     */
    private function lastMoveOf(array $sessions): ?CarbonImmutable
    {
        $lastMoveAt = null;

        foreach ($sessions as $session) {
            if (null !== $session->updated_at && (null === $lastMoveAt || $session->updated_at->gt($lastMoveAt))) {
                $lastMoveAt = CarbonImmutable::instance($session->updated_at);
            }
        }

        return $lastMoveAt;
    }

    /**
     * Every session of the tenant's run under {@code $root}, of any status: a child that is already over still counts
     * as the run's last movement.
     *
     * @return list<FlowSession>
     */
    private function sessionsOfRun(string $tenantId, FlowSession $root): array
    {
        return array_merge(...$this->handOff->sessionsOfRunByLevel($tenantId, $root));
    }

    /**
     * The setting, held within its bounds: the settings store checks only a stored value, so an installation default
     * outside them is clamped here.
     */
    private function minutes(): int
    {
        return min(self::MAX_MINUTES, max(self::MIN_MINUTES, $this->platformSettings->integer(self::SETTING)));
    }
}
