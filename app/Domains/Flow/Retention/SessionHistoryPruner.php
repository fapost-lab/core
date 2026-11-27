<?php

declare(strict_types=1);

namespace App\Domains\Flow\Retention;

use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowSessionHistoryEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Deletes the `flow_session_history` of one tenant's conversation sessions that finished before the cutoff. A deletion
 * cannot be undone, so the session rows stay and only whole histories go.
 *
 * A session's history may go only when the session belongs to the tenant, is a conversation (`run_kind`), has a status
 * in {@see AutomationRunRetention::TERMINAL} — a status a session never leaves, and a finished session starts nothing,
 * so no lock is needed — and finished (`updated_at`) before the cutoff. A subflow chain — a root session and every
 * session below it by `parent_session_id` — loses its history only as a whole, once every member may: a live, young,
 * foreign or automation member keeps the whole chain, and a child is never picked on its own, so a run graph never
 * loses a part. The whole history of each session goes at once, so a trace never loses its beginning. An automation
 * run's history is never touched here: it goes with its run ({@see AutomationRunPruner}).
 *
 * Roots that still have history are taken in batches ordered by `(updated_at, id)`, one `DELETE` a batch that repeats
 * every condition, and at most `maxBatches` a call, so a backlog drains over several runs. The tenant is passed
 * explicitly and every query is filtered by it, beside the tenant schema the caller switched to.
 */
final readonly class SessionHistoryPruner
{
    /** Deeper than a subflow chain can be (ADR-11 allows three levels); a chain past it is skipped, not cut. */
    public const int MAX_CHAIN_DEPTH = AutomationRunPruner::MAX_CHAIN_DEPTH;

    public function __construct(
        private int $batchSize = 200,
        private int $maxBatches = 250,
    ) {
    }

    /**
     * @return int how many sessions lost their history, or would on a dry run
     */
    public function prune(string $tenantId, CarbonImmutable $cutoff, bool $dryRun = false): int
    {
        $pruned = 0;
        $after  = null;

        for ($batch = 0; $batch < $this->maxBatches; ++$batch) {
            $roots = $this->rootCandidates($tenantId, $cutoff, $after);

            if ([] === $roots) {
                break;
            }

            $last  = $roots[count($roots) - 1];
            $after = ['updated_at' => $last['updated_at'], 'id' => $last['id']];

            $pruned += $this->pruneChains($tenantId, array_column($roots, 'id'), $cutoff, $dryRun);

            if (count($roots) < $this->batchSize) {
                break;
            }
        }

        return $pruned;
    }

    /**
     * The next batch of root sessions that may go by their own row and still have history. Only a narrowing: every
     * chain below them is checked as a whole.
     *
     * @param  array{updated_at: string, id: string}|null  $after
     * @return list<array{id: string, updated_at: string}>
     */
    private function rootCandidates(string $tenantId, CarbonImmutable $cutoff, ?array $after): array
    {
        $query = $this->prunable($tenantId, $cutoff)
            ->whereNull('flow_sessions.parent_session_id')
            ->whereExists(fn (QueryBuilder $query): QueryBuilder => $query
                ->selectRaw('1')
                ->from('flow_session_history')
                ->whereColumn('flow_session_history.session_id', 'flow_sessions.id'));

        if (null !== $after) {
            $query->where(fn (Builder $query): Builder => $query
                ->where('flow_sessions.updated_at', '>', $after['updated_at'])
                ->orWhere(fn (Builder $query): Builder => $query
                    ->where('flow_sessions.updated_at', '=', $after['updated_at'])
                    ->where('flow_sessions.id', '>', $after['id'])));
        }

        /** @var list<array{id: string, updated_at: string}> $rows */
        $rows = $query
            ->orderBy('flow_sessions.updated_at')
            ->orderBy('flow_sessions.id')
            ->limit($this->batchSize)
            ->toBase()
            ->get(['flow_sessions.id', 'flow_sessions.updated_at'])
            ->map(static fn (object $row): array => ['id' => (string) $row->id, 'updated_at' => (string) $row->updated_at])
            ->all();

        return $rows;
    }

    /**
     * Keeps the chains under the given roots whose every member may go, and deletes their history.
     *
     * @param  list<string>  $rootIds
     * @return int how many sessions lost their history, or would
     */
    private function pruneChains(string $tenantId, array $rootIds, CarbonImmutable $cutoff, bool $dryRun): int
    {
        [$chains, $tooDeep] = $this->chains($rootIds);

        $memberIds = array_merge(...array_values($chains));

        /** @var array<string, true> $mayGo */
        $mayGo = array_fill_keys(
            $this->prunable($tenantId, $cutoff)
                ->whereKey($memberIds)
                ->toBase()
                ->pluck('flow_sessions.id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all(),
            true,
        );

        $doomed = [];

        foreach ($chains as $rootId => $ids) {
            if (isset($tooDeep[$rootId])) {
                continue;
            }

            foreach ($ids as $id) {
                if (! isset($mayGo[$id])) {
                    continue 2;
                }
            }

            array_push($doomed, ...$ids);
        }

        if ([] === $doomed || $dryRun) {
            return count($doomed);
        }

        FlowSessionHistoryEntry::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('session_id', $this->prunable($tenantId, $cutoff)->whereKey($doomed)->select('flow_sessions.id'))
            ->delete();

        return count($doomed);
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

    /**
     * Every condition a session's own row must meet for its history to go, written once for the queries and the
     * delete.
     *
     * @return Builder<FlowSession>
     */
    private function prunable(string $tenantId, CarbonImmutable $cutoff): Builder
    {
        return FlowSession::query()
            ->where('flow_sessions.tenant_id', $tenantId)
            ->where('flow_sessions.run_kind', FlowRunKind::Conversation->value)
            ->whereIn('flow_sessions.status', AutomationRunRetention::terminalValues())
            ->where('flow_sessions.updated_at', '<', $cutoff);
    }
}
