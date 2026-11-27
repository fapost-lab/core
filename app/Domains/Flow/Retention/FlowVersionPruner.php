<?php

declare(strict_types=1);

namespace App\Domains\Flow\Retention;

use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\PersistentInlineButton;
use App\Domains\Flow\Subflow\SubflowPin;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;

/**
 * Deletes one tenant's old published flow versions, keeping the `keepLast` highest versions of every flow. A deletion
 * cannot be undone, so every version is checked twice: the query narrows, and each one is checked again under a row
 * lock before anything is deleted.
 *
 * A version may be deleted only when it belongs to the tenant, is outside the `keepLast` highest versions of its flow,
 * and is neither the flow's active version nor its highest one (the next publish numbers itself `max + 1`; deleting
 * the highest would hand its number to another graph). It must also be needed by nothing: no `flow_sessions` row of any
 * status points at it (the foreign key restricts that delete anyway), no `persistent_inline_buttons` row does (an old
 * button would cancel the contact's session and do nothing), and no subflow node in any draft or published version pins
 * its flow and number (`config.flow_version`, {@see SubflowPin}). Those references are read across the whole schema,
 * whatever their tenant, and pins are read from the versions about to go too: a version kept only by such a pin goes on
 * a later run.
 *
 * The version is deleted as a model, so `deleted` listeners run (Media drops its references); the database cascades
 * its call graph edges and template usages. Versions are deleted in batches, one transaction a batch, and at most
 * `maxBatches` a call, so a backlog drains over several runs.
 */
final readonly class FlowVersionPruner
{
    /**
     * The models whose rows keep a version alive through their `flow_definition_id`, of any status. A new table that
     * points at `flow_definitions.id` belongs here; both the narrowing query and the check under the lock read this list.
     *
     * @var list<class-string<Model>>
     */
    public const array HOLDERS = [
        FlowSession::class,
        PersistentInlineButton::class,
    ];

    public function __construct(
        private int $batchSize = 200,
        private int $maxBatches = 250,
    ) {
    }

    /**
     * @return int how many versions were deleted, or would be on a dry run
     */
    public function prune(string $tenantId, int $keepLast, bool $dryRun = false): int
    {
        if ($keepLast <= 0) {
            return 0;
        }

        $candidates = $this->candidates($tenantId, $keepLast);

        if ([] === $candidates) {
            return 0;
        }

        // A pin written after this scan is on a row changed since; a day covers any publish still open across it.
        $changedSince = CarbonImmutable::now()->subDay();
        $pins         = $this->pinnedVersions();
        $candidates   = array_values(array_filter(
            $candidates,
            static fn (array $row): bool => ! isset($pins[self::pinKey($row['flow_id'], $row['version'])]),
        ));

        $deleted = 0;

        foreach (array_slice(array_chunk($candidates, $this->batchSize), 0, $this->maxBatches) as $batch) {
            $deleted += FlowDefinition::query()->getConnection()->transaction(
                fn (): int => $this->pruneBatch($tenantId, $keepLast, array_column($batch, 'id'), $changedSince, $dryRun),
            );
        }

        return $deleted;
    }

    private static function pinKey(string $flowId, int $version): string
    {
        return $flowId . '@' . $version;
    }

    /**
     * The versions outside the `keepLast` highest of their flow that look unneeded by their own row and by sessions
     * and buttons. Only a narrowing: every one is checked again under a lock.
     *
     * @return list<array{id: string, flow_id: string, version: int}>
     */
    private function candidates(string $tenantId, int $keepLast): array
    {
        $flowIds = FlowDefinition::query()
            ->where('tenant_id', $tenantId)
            ->groupBy('flow_id')
            ->havingRaw('COUNT(*) > ?', [$keepLast])
            ->orderBy('flow_id')
            ->pluck('flow_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $candidates = [];

        foreach ($flowIds as $flowId) {
            $rows = $this->unreferenced(FlowDefinition::query()
                ->where('tenant_id', $tenantId)
                ->where('flow_id', $flowId)
                ->where('is_active', false))
                ->where('version', '<', $this->keptFloor($tenantId, $flowId, $keepLast))
                ->orderBy('version')
                ->toBase()
                ->get(['id', 'flow_id', 'version']);

            foreach ($rows as $row) {
                $candidates[] = ['id' => (string) $row->id, 'flow_id' => (string) $row->flow_id, 'version' => (int) $row->version];
            }
        }

        return $candidates;
    }

    /**
     * The lowest version number the flow keeps: its `keepLast`-th highest.
     */
    private function keptFloor(string $tenantId, string $flowId, int $keepLast): int
    {
        return (int) FlowDefinition::query()
            ->where('tenant_id', $tenantId)
            ->where('flow_id', $flowId)
            ->orderByDesc('version')
            ->offset($keepLast - 1)
            ->limit(1)
            ->value('version');
    }

    /**
     * Locks the batch, checks every version again — the pins of its flows read afresh after the lock from the drafts
     * and versions changed since `$changedSince` — and deletes the ones that may go. A publish that pins one of these
     * versions holds `FOR KEY SHARE` on it while it validates (`ValidateFlowService`): the lock here skips such a row
     * (`SKIP LOCKED`), so the prune never waits on a publish and cannot deadlock with one, and a publish that locks after
     * this commits finds the version gone and is refused. A dry run takes no lock.
     *
     * @param  list<string>  $ids
     */
    private function pruneBatch(string $tenantId, int $keepLast, array $ids, CarbonImmutable $changedSince, bool $dryRun): int
    {
        $query = FlowDefinition::query()->whereKey($ids);

        /** @var list<FlowDefinition> $locked */
        $locked = ($dryRun ? $query : $query->lock('for update skip locked'))->get()->all();

        $pins = $this->pinnedVersions(array_values(array_unique(array_map(
            static fn (FlowDefinition $definition): string => (string) $definition->flow_id,
            $locked,
        ))), $changedSince);

        $referenced = [];

        foreach (self::HOLDERS as $holder) {
            foreach ($holder::query()->whereIn('flow_definition_id', $ids)->distinct()->pluck('flow_definition_id') as $id) {
                $referenced[(string) $id] = true;
            }
        }

        $floors = [];
        $doomed = [];

        foreach ($locked as $definition) {
            $flowId = (string) $definition->flow_id;
            $floors[$flowId] ??= $this->keptFloor($tenantId, $flowId, $keepLast);

            if ($definition->tenant_id !== $tenantId
                || $definition->is_active
                || $definition->version >= $floors[$flowId]
                || isset($referenced[(string) $definition->getKey()])
                || isset($pins[self::pinKey($flowId, $definition->version)])) {
                continue;
            }

            $doomed[] = $definition;
        }

        if ([] === $doomed || $dryRun) {
            return count($doomed);
        }

        foreach ($doomed as $definition) {
            if (true !== $definition->delete()) {
                throw new LogicException(sprintf(
                    'Flow version retention checked version %d of flow %s under a lock but could not delete it; rolled back.',
                    $definition->version,
                    $definition->flow_id,
                ));
            }
        }

        return count($doomed);
    }

    /**
     * Narrows a definition query to the rows no session and no persistent button points at.
     *
     * @param  Builder<FlowDefinition>  $query
     * @return Builder<FlowDefinition>
     */
    private function unreferenced(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        foreach (self::HOLDERS as $holder) {
            $query->whereNotExists(static fn (QueryBuilder $rows): QueryBuilder => $rows
                ->selectRaw('1')
                ->from((new $holder())->getTable())
                ->whereColumn('flow_definition_id', "{$table}.id"));
        }

        return $query;
    }

    /**
     * Every `flow_id@version` a subflow node pins, in any draft or published version of the schema; with `$flowIds`,
     * only the pins of those flows, and with `$changedSince`, only in rows updated since then.
     *
     * @param  list<string>|null  $flowIds
     * @return array<string, true>
     */
    private function pinnedVersions(?array $flowIds = null, ?CarbonImmutable $changedSince = null): array
    {
        if ([] === $flowIds) {
            return [];
        }

        $pins = [];

        foreach ([FlowDraft::query(), FlowDefinition::query()] as $query) {
            $connection = $query->getConnection();
            $column     = 'pgsql' === $connection->getDriverName() ? 'nodes::text' : 'nodes';

            $query->toBase()
                ->whereRaw("{$column} LIKE ?", ['%' . SubflowPin::FIELD . '%'])
                ->when(null !== $flowIds, static fn (QueryBuilder $query): QueryBuilder => $query->where(
                    static function (QueryBuilder $query) use ($column, $flowIds): void {
                        foreach ($flowIds ?? [] as $flowId) {
                            $query->orWhereRaw("{$column} LIKE ?", ['%' . $flowId . '%']);
                        }
                    },
                ))
                ->when(null !== $changedSince, static fn (QueryBuilder $query): QueryBuilder => $query->where('updated_at', '>=', $changedSince))
                ->select(['id', 'nodes'])
                ->chunkById(500, function (iterable $rows) use (&$pins): void {
                    foreach ($rows as $row) {
                        $nodes = is_string($row->nodes) ? json_decode($row->nodes, true) : $row->nodes;

                        foreach (is_array($nodes) ? $nodes : [] as $node) {
                            $pin = $this->pinOf($node);

                            if (null !== $pin) {
                                $pins[$pin] = true;
                            }
                        }
                    }
                });
        }

        return $pins;
    }

    private function pinOf(mixed $node): ?string
    {
        $target = SubflowPin::target($node);

        return null === $target ? null : self::pinKey(...$target);
    }
}
