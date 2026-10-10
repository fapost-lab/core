<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use Illuminate\Support\Carbon;

/**
 * Counts of what one assistant's flows are doing now, for its dashboard.
 */
final class AssistantFlowActivity
{
    /**
     * Sessions that are running or waiting for something (not finished, not failed).
     */
    public function liveSessions(string $assistantId): int
    {
        return FlowSession::query()
            ->where('assistant_id', $assistantId)
            ->whereIn('status', array_map(static fn (FlowSessionStatus $status): string => $status->value, FlowSessionStatus::live()))
            ->count();
    }

    /**
     * Flow log entries that carry an error and were written in the last 24 hours.
     */
    public function errorsLast24Hours(string $assistantId): int
    {
        return FlowLog::query()
            ->whereNotNull('error')
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->whereHas('session', static fn ($query) => $query->where('assistant_id', $assistantId))
            ->count();
    }
}
