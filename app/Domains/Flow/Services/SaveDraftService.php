<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Exceptions\DraftVersionConflictException;
use App\Domains\Flow\Models\FlowDraft;
use Illuminate\Support\Facades\DB;

final class SaveDraftService
{
    /**
     * @param  array<string, mixed>  $nodes
     */
    public function execute(string $flowId, array $nodes, int $expectedDraftVersion): int
    {
        $affected = FlowDraft::query()
            ->where('flow_id', $flowId)
            ->where('draft_version', $expectedDraftVersion)
            ->update([
                'nodes'         => json_encode($nodes, JSON_THROW_ON_ERROR),
                'draft_version' => DB::raw('draft_version + 1'),
                'updated_at'    => now(),
            ]);

        if (0 === $affected) {
            throw new DraftVersionConflictException($flowId);
        }

        return $expectedDraftVersion + 1;
    }
}
