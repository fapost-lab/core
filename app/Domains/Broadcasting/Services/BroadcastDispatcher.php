<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Services;

use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Jobs\RunBroadcastJob;
use App\Domains\Broadcasting\Models\Broadcast;
use Illuminate\Support\Carbon;

/**
 * Starts a broadcast run. The Draft → Running transition is a single conditional
 * UPDATE so a double-click / concurrent send can only win once; the fan-out job
 * is dispatched only by the winner.
 */
final class BroadcastDispatcher
{
    /**
     * @return bool  true when this call started the run, false when it was already started.
     */
    public function start(Broadcast $broadcast): bool
    {
        $started = Broadcast::query()
            ->whereKey($broadcast->getKey())
            ->where('status', BroadcastStatus::Draft->value)
            ->update([
                'status'     => BroadcastStatus::Running->value,
                'started_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        if (1 !== $started) {
            return false;
        }

        // After the commit: a caller that starts inside a transaction would otherwise let the job run before the status
        // is visible, find a draft and leave the broadcast Running with nobody working on it.
        RunBroadcastJob::dispatch((string) $broadcast->tenant_id, (string) $broadcast->getKey())->afterCommit();

        return true;
    }
}
