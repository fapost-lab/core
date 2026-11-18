<?php

declare(strict_types=1);

namespace App\Domains\Flow\Retention;

use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowSessionHistoryEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Deletes one tenant's finished automation runs older than their retention, with their `flow_session_history` and
 * `flow_logs`. A deletion cannot be undone, so every run is checked twice: the query narrows, and each session is
 * checked again under a row lock before anything is deleted.
 *
 * A session may be deleted only when it belongs to the tenant, is an automation (`run_kind`), has a status in
 * {@see AutomationRunRetention::TERMINAL} and finished (`updated_at`) before the cutoff of its class — succeeded or
 * not, see {@see AutomationRunRetention::isSucceeded()}; a null cutoff keeps that class. A subflow chain — a root
 * session and every session below it by `parent_session_id` — goes only as a whole, once every member may be
 * deleted: a live, young or conversational member keeps the whole chain, and a child is never picked on its own.
 *
 * Roots are taken in batches ordered by `(updated_at, id)`, one transaction a batch, and at most `maxBatches` a call,
 * so a backlog drains over several runs and no batch holds its locks for long. The tenant is passed explicitly and
 * every query is filtered by it, beside the tenant schema the caller switched to.
 */
final readonly class AutomationRunPruner
{
    /** Deeper than a subflow chain can be (ADR-11 allows three levels); a chain past it is skipped, not cut. */
    public const int MAX_CHAIN_DEPTH = 5;

    public function __construct(
        private int $batchSize = 200,
        private int $maxBatches = 250,
    ) {
    }

    /**
     * @return int how many sessions were deleted, or would be on a dry run
     */
    public function prune(
        string $tenantId,
        ?CarbonImmutable $succeededCutoff,
        ?CarbonImmutable $failedCutoff,
        bool $dryRun = false,
    ): int {
        if (null === $succeededCutoff && null === $failedCutoff) {
            return 0;
        }

        $deleted = 0;
        $after   = null;

        for ($batch = 0; $batch < $this->maxBatches; ++$batch) {
            $roots = $this->rootCandidates($tenantId, $succeededCutoff, $failedCutoff, $after);

            if ([] === $roots) {
                break;
            }

            $last  = $roots[count($roots) - 1];
            $after = ['updated_at' => $last['updated_at'], 'id' => $last['id']];

            $deleted += FlowSession::query()->getConnection()->transaction(
                fn (): int => $this->pruneChains(
                    $tenantId,
                    array_column($roots, 'id'),
                    $succeededCutoff,
                    $failedCutoff,
                    $dryRun,
                ),
            );

            if (count($roots) < $this->batchSize) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * The next batch of root sessions that look prunable by their own row. Only a narrowing: every one is checked
     * again, with its chain, under a lock.
     *
     * @param  array{updated_at: string, id: string}|null  $after
     * @return list<array{id: string, updated_at: string}>
     */
    private function rootCandidates(
        string $tenantId,
        ?CarbonImmutable $succeededCutoff,
        ?CarbonImmutable $failedCutoff,
        ?array $after,
    ): array {
        $succeeded = "(status = 'completed' OR (status = 'ended' AND COALESCE(end_status, '') = 'success'))";

        $query = FlowSession::query()
            ->where('tenant_id', $tenantId)
            ->where('run_kind', FlowRunKind::Automation->value)
            ->whereNull('parent_session_id')
            ->whereIn('status', AutomationRunRetention::terminalValues())
            ->where(function (Builder $query) use ($succeeded, $succeededCutoff, $failedCutoff): void {
                if (null !== $succeededCutoff) {
                    $query->orWhere(fn (Builder $query): Builder => $query
                        ->whereRaw($succeeded)
                        ->where('updated_at', '<', $succeededCutoff));
                }

                if (null !== $failedCutoff) {
                    $query->orWhere(fn (Builder $query): Builder => $query
                        ->whereRaw("NOT {$succeeded}")
                        ->where('updated_at', '<', $failedCutoff));
                }
            });

        if (null !== $after) {
            $query->where(fn (Builder $query): Builder => $query
                ->where('updated_at', '>', $after['updated_at'])
                ->orWhere(fn (Builder $query): Builder => $query
                    ->where('updated_at', '=', $after['updated_at'])
                    ->where('id', '>', $after['id'])));
        }

        /** @var list<array{id: string, updated_at: string}> $rows */
        $rows = $query
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($this->batchSize)
            ->toBase()
            ->get(['id', 'updated_at'])
            ->map(static fn (object $row): array => ['id' => (string) $row->id, 'updated_at' => (string) $row->updated_at])
            ->all();

        return $rows;
    }

    /**
     * Locks the chains under the given roots, keeps the ones whose every member may go, and deletes them.
     *
     * @param  list<string>  $rootIds
     */
    private function pruneChains(
        string $tenantId,
        array $rootIds,
        ?CarbonImmutable $succeededCutoff,
        ?CarbonImmutable $failedCutoff,
        bool $dryRun,
    ): int {
        [$chains, $tooDeep] = $this->chains($rootIds);

        $memberIds = array_merge(...array_values($chains));

        /** @var array<string, FlowSession> $members */
        $members = FlowSession::query()
            ->whereKey($memberIds)
            ->lockForUpdate()
            ->get()
            ->keyBy(static fn (FlowSession $session): string => (string) $session->getKey())
            ->all();

        $doomed = [];

        foreach ($chains as $rootId => $ids) {
            if (isset($tooDeep[$rootId])) {
                continue;
            }

            $root = $members[$rootId] ?? null;

            if (null === $root || null !== $root->getAttribute('parent_session_id')) {
                continue;
            }

            foreach ($ids as $id) {
                $member = $members[$id] ?? null;

                if (null === $member || ! $this->mayDelete($member, $tenantId, $succeededCutoff, $failedCutoff)) {
                    continue 2;
                }
            }

            array_push($doomed, ...$ids);
        }

        if ([] === $doomed || $dryRun) {
            return count($doomed);
        }

        FlowSessionHistoryEntry::query()->whereIn('session_id', $doomed)->delete();
        FlowLog::query()->whereIn('session_id', $doomed)->delete();

        $deleted = FlowSession::query()
            ->whereKey($doomed)
            ->where('tenant_id', $tenantId)
            ->where('run_kind', FlowRunKind::Automation->value)
            ->whereIn('status', AutomationRunRetention::terminalValues())
            ->delete();

        if ($deleted !== count($doomed)) {
            throw new LogicException(sprintf(
                'Automation run retention checked %d sessions under a lock but the delete matched %d; rolled back.',
                count($doomed),
                $deleted,
            ));
        }

        return $deleted;
    }

    /**
     * Every root's chain, the root first, found level by level through `parent_session_id` whatever the tenant, kind
     * or status of a child — a child that may not go must be seen to keep its chain.
     *
     * @param  list<string>  $rootIds
     * @return array{0: array<string, list<string>>, 1: array<string, true>} the members of each root's chain, and
     *                                                                        the roots whose chain is deeper than allowed
     */
    private function chains(array $rootIds): array
    {
        $chains  = [];
        $rootOf  = [];
        $tooDeep = [];

        foreach ($rootIds as $rootId) {
            $chains[$rootId] = [$rootId];
            $rootOf[$rootId] = $rootId;
        }

        $frontier = $rootIds;

        for ($depth = 0; $depth <= self::MAX_CHAIN_DEPTH && [] !== $frontier; ++$depth) {
            $children = FlowSession::query()
                ->whereIn('parent_session_id', $frontier)
                ->toBase()
                ->get(['id', 'parent_session_id']);

            $frontier = [];

            foreach ($children as $child) {
                $childId = (string) $child->id;
                $rootId  = $rootOf[(string) $child->parent_session_id];

                if (self::MAX_CHAIN_DEPTH === $depth) {
                    $tooDeep[$rootId] = true;

                    continue;
                }

                $rootOf[$childId]  = $rootId;
                $chains[$rootId][] = $childId;
                $frontier[]        = $childId;
            }
        }

        return [$chains, $tooDeep];
    }

    private function mayDelete(
        FlowSession $session,
        string $tenantId,
        ?CarbonImmutable $succeededCutoff,
        ?CarbonImmutable $failedCutoff,
    ): bool {
        if ($session->tenant_id !== $tenantId
            || FlowRunKind::Automation !== $session->run_kind
            || ! AutomationRunRetention::isTerminal($session->status)
            || null === $session->updated_at) {
            return false;
        }

        $endStatus = $session->getAttribute('end_status');
        $cutoff    = AutomationRunRetention::isSucceeded($session->status, is_string($endStatus) ? $endStatus : null)
            ? $succeededCutoff
            : $failedCutoff;

        return null !== $cutoff && $session->updated_at->lessThan($cutoff);
    }
}
