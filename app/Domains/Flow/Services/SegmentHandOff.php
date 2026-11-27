<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\AfterCommitDispatcherInterface;
use App\Domains\Flow\Contracts\AutomationContinuationSchedulerInterface;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowSession;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;

/**
 * Hands an automation run on to a new job: writes a new segment id on the run's root session and, after that commit,
 * queues the continuation that carries it. Only that continuation is valid from then on; any other is stale.
 *
 * The engine calls it when a segment's budget ran out. An entry point that wakes a run outside the automation jobs
 * (a delay wake-up, the subflow sweeper) calls {@see handOnIfStranded()} when its own job broke off after the run was
 * already moved on — a lost lock — so the run, now `active` with nothing queued, still continues.
 *
 * A run can also strand with its subflow child already over and its parent still in `paused_subflow`: the job broke
 * off after the child's last step committed and before the parent was resumed. {@see pendingReturnOf()} names that
 * child; only {@see \App\Domains\Flow\Orchestration\AutomationRunner} resumes the parent with it, under the run's
 * lock on the automation queue (adr-20261125-stranded-automation-run-recovery). {@see strandedShapeOf()} is the one
 * answer to "has the run something left to run"; {@see sessionsOfRunByLevel()} the one walk down a run, and
 * {@see chainOf()} the one walk up it.
 */
final readonly class SegmentHandOff
{
    /** A subflow chain is at most a few levels deep; the bound only guards against a corrupt cycle. */
    public const int MAX_CHAIN_DEPTH = 10;

    /** A run with an `active` session ({@see liveSessionOf()}). */
    public const string SHAPE_ACTIVE = 'active';

    /** A run whose parent still waits on a subflow child that is already over ({@see pendingReturnOf()}). */
    public const string SHAPE_SUBFLOW_RETURN = 'subflow_return';

    /**
     * The statuses of a subflow child that is over and hands control back to its parent. An `expired` child — an orphan
     * the subflow sweeper expired — hands it back as failed.
     */
    private const array RETURNED = [
        FlowSessionStatus::Ended,
        FlowSessionStatus::Completed,
        FlowSessionStatus::Failed,
        FlowSessionStatus::Expired,
    ];

    /**
     * @param  ConnectionResolverInterface  $db  asked for its connection at the moment it writes, never kept: a tenant
     *                                         switch moves the default connection the models write through, and a
     *                                         transaction on a connection taken when this is built — before the
     *                                         switch, in a queue job — would not cover them
     */
    public function __construct(
        private ConnectionResolverInterface $db,
        private AfterCommitDispatcherInterface $afterCommitDispatcher,
        private AutomationContinuationSchedulerInterface $continuations,
    ) {
    }

    public function handOn(FlowSession $session): void
    {
        $segmentId = (string) Str::uuid();
        $rootId    = $this->rootIdOf($session);
        $tenantId  = (string) $session->tenant_id;

        $this->db->connection()->transaction(function () use ($rootId, $tenantId, $segmentId): void {
            FlowSession::query()->whereKey($rootId)->update(['segment_id' => $segmentId]);

            $this->afterCommitDispatcher->afterCommit(function () use ($tenantId, $rootId, $segmentId): void {
                $this->continuations->schedule($tenantId, $rootId, $segmentId);
            });
        });
    }

    /**
     * Hand on the automation run of {@code $session} when one of its sessions is `active` — moved past where the
     * caller found it, with no job of its own left to run it — or when a parent still waits on a subflow child that is
     * already over ({@see pendingReturnOf()}); the continuation then resumes the parent. A conversation, or a run that
     * is parked or over, is left as it is. A continuation already queued for the run becomes stale; the new one
     * replaces it.
     */
    public function handOnIfStranded(FlowSession $session): void
    {
        if (FlowRunKind::Automation !== $session->run_kind) {
            return;
        }

        $root = FlowSession::query()->find($this->rootIdOf($session));

        // A parent left waiting on a child that is already over is handed on too: the continuation resumes it.
        if (null !== $root && null !== $this->strandedShapeOf($root)) {
            $this->handOn($root);
        }
    }

    /**
     * What the run under {@code $root} has left to run, if anything: an `active` session ({@see self::SHAPE_ACTIVE}),
     * or a subflow child's end to hand back to its parent ({@see self::SHAPE_SUBFLOW_RETURN}). Null when the run is
     * parked, over or failed. Only reads.
     *
     * @return self::SHAPE_*|null
     */
    public function strandedShapeOf(FlowSession $root): ?string
    {
        if (null !== $this->liveSessionOf($root)) {
            return self::SHAPE_ACTIVE;
        }

        return null !== $this->pendingReturnOf($root) ? self::SHAPE_SUBFLOW_RETURN : null;
    }

    /**
     * Queue again the continuation the run's root already waits for, with the same segment id, after the current
     * transaction commits. A continuation of that id still queued stays valid: whichever of the two runs first takes
     * the run on, and the other finds the segment id moved on, or nothing left to run.
     */
    public function redispatch(FlowSession $root): void
    {
        $segmentId = $root->segment_id;

        if (! is_string($segmentId) || '' === $segmentId) {
            return;
        }

        $tenantId = (string) $root->tenant_id;
        $rootId   = (string) $root->getKey();

        $this->afterCommitDispatcher->afterCommit(function () use ($tenantId, $rootId, $segmentId): void {
            $this->continuations->schedule($tenantId, $rootId, $segmentId);
        });
    }

    /**
     * The `active` session of a run, if it has one: the root itself, or — down the chain of `paused_subflow`
     * parents — the child that runs in their place. Null when the run is parked, ended or failed.
     */
    public function liveSessionOf(FlowSession $root): ?FlowSession
    {
        $session = $root;

        for ($level = 0; $level < self::MAX_CHAIN_DEPTH; ++$level) {
            if (FlowSessionStatus::Active === $session->status) {
                return $session;
            }

            if (FlowSessionStatus::PausedSubflow !== $session->status) {
                return null;
            }

            $child = FlowSession::query()
                ->where('parent_session_id', $session->getKey())
                ->whereIn('status', [FlowSessionStatus::Active->value, FlowSessionStatus::PausedSubflow->value])
                ->latest('created_at')
                ->first();

            if (null === $child) {
                return null;
            }

            $session = $child;
        }

        return null;
    }

    /**
     * The subflow child whose end the run still has to hand back, if it has one: down the chain of `paused_subflow`
     * parents, the first parent whose latest child is over (`ended`, `completed`, `failed` or `expired`) while the parent still
     * waits on the node that started that child. Null when the run has an `active` session, or is parked, over, or
     * failed as a whole. Only reads; resuming the parent is {@see \App\Domains\Flow\Orchestration\AutomationRunner}'s.
     */
    public function pendingReturnOf(FlowSession $root): ?FlowSession
    {
        $session = $root;

        for ($level = 0; $level < self::MAX_CHAIN_DEPTH; ++$level) {
            if (FlowSessionStatus::PausedSubflow !== $session->status) {
                return null;
            }

            // The latest child of any status: an older child that ended in an earlier loop iteration is not the one
            // the parent waits on. Ids are ULIDs, so they break a tie of `created_at` within a second.
            $child = FlowSession::query()
                ->where('parent_session_id', $session->getKey())
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if (null === $child) {
                return null;
            }

            if (in_array($child->status, self::RETURNED, true)) {
                $waitsOn = $session->current_node_id;

                return is_string($waitsOn) && '' !== $waitsOn && $child->parent_resume_node_id === $waitsOn
                    ? $child
                    : null;
            }

            $session = $child;
        }

        return null;
    }

    /**
     * The top session of a subflow chain: the run a trigger started. When a row of the chain is gone, the id the
     * chain last names — the root a continuation is queued for — even though that row no longer exists. Reads only
     * `parent_session_id` per level: it runs at every segment end of a subflow child.
     */
    public function rootIdOf(FlowSession $session): string
    {
        return $this->walkUp($session)['root_id'];
    }

    /**
     * The one walk up a subflow chain ({@see walkUp()}): the id the chain names as its root ({@see rootIdOf()}), and
     * the highest session of the chain that still exists — the root itself, or, when the root row is gone, the session
     * that names it as its parent — loaded in full once. Should that row be deleted in between, the session given.
     *
     * @return array{root_id: string, top: FlowSession}
     */
    public function chainOf(FlowSession $session): array
    {
        ['root_id' => $rootId, 'top_id' => $topId] = $this->walkUp($session);

        $top = $topId === (string) $session->getKey() ? $session : FlowSession::query()->find($topId);

        return ['root_id' => $rootId, 'top' => $top ?? $session];
    }

    /**
     * Every session of the run under {@code $top} — itself and every session of the tenant below it by
     * `parent_session_id`, of any status — level by level from the top, bounded by {@see self::MAX_CHAIN_DEPTH}.
     *
     * @return list<list<FlowSession>>
     */
    public function sessionsOfRunByLevel(string $tenantId, FlowSession $top): array
    {
        $levels    = [[$top]];
        $parentIds = [(string) $top->getKey()];

        for ($depth = 1; $depth < self::MAX_CHAIN_DEPTH; ++$depth) {
            $children = FlowSession::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('parent_session_id', $parentIds)
                ->get()
                ->all();

            if ([] === $children) {
                break;
            }

            $levels[]  = $children;
            $parentIds = array_map(static fn (FlowSession $child): string => (string) $child->getKey(), $children);
        }

        return $levels;
    }

    /**
     * The walk up a subflow chain by `parent_session_id`, bounded by {@see self::MAX_CHAIN_DEPTH}, reading that one
     * column per level: the id the chain names as its root, and the id of the highest session that still exists.
     *
     * @return array{root_id: string, top_id: string}
     */
    private function walkUp(FlowSession $session): array
    {
        $rootId   = (string) $session->getKey();
        $topId    = $rootId;
        $parentId = $session->parent_session_id;

        for ($level = 0; $level < self::MAX_CHAIN_DEPTH && is_string($parentId) && '' !== $parentId; ++$level) {
            $rootId = $parentId;
            $parent = FlowSession::query()->whereKey($parentId)->toBase()->first(['parent_session_id']);

            if (null === $parent) {
                break;
            }

            $topId    = $parentId;
            $parentId = $parent->parent_session_id;
        }

        return ['root_id' => $rootId, 'top_id' => $topId];
    }
}
