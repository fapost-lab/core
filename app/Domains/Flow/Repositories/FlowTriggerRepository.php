<?php

declare(strict_types=1);

namespace App\Domains\Flow\Repositories;

use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Models\FlowTrigger;
use Illuminate\Database\Eloquent\Builder;

final class FlowTriggerRepository implements FlowTriggerRepositoryInterface
{
    public function getActiveByType(string $tenantId, ?string $assistantId, string $type): iterable
    {
        $query = FlowTrigger::query()
            ->where('tenant_id', $tenantId)
            ->where('type', $type)
            ->where('is_active', true);

        if (null !== $assistantId) {
            $query->where(static function (Builder $subQuery) use ($assistantId): void {
                $subQuery
                    ->where('assistant_id', $assistantId)
                    ->orWhereNull('assistant_id');
            })
                // Deterministic precedence: assistant-specific triggers first, global fallback second.
                ->orderByRaw('CASE WHEN assistant_id IS NULL THEN 1 ELSE 0 END');
        } else {
            $query->whereNull('assistant_id');
        }

        return $query
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }
}
