<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Exceptions\DraftVersionConflictException;
use App\Domains\Flow\Models\FlowDraft;
use Illuminate\Support\Facades\DB;

final class SaveDraftService
{
    public function __construct(
        private readonly SyncFlowTriggerService $syncTrigger,
    ) {
    }

    /**
     * @param  array<string, mixed>  $nodes
     * @param  array<string, mixed>  $edges
     * @param  array<string, mixed>|null  $trigger
     */
    public function execute(string $flowId, array $nodes, array $edges, ?array $trigger, int $expectedDraftVersion): int
    {
        return DB::transaction(function () use ($flowId, $nodes, $edges, $trigger, $expectedDraftVersion): int {
            $draft = FlowDraft::query()
                ->where('flow_id', $flowId)
                ->where('draft_version', $expectedDraftVersion)
                ->lockForUpdate()
                ->first();

            if (null === $draft) {
                throw new DraftVersionConflictException($flowId);
            }

            $draft->forceFill([
                'nodes'         => $nodes,
                'edges'         => $edges,
                'draft_version' => $draft->draft_version + 1,
            ])->save();

            $this->syncTrigger->execute($draft, $trigger);

            return $draft->draft_version;
        });
    }
}
