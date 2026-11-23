<?php

declare(strict_types=1);

namespace App\Domains\Flow\DTOs;

use App\Domains\Flow\Enums\AudienceBroadcastOutcome;

/**
 * The answer to an {@see AudienceBroadcastRequest}. When the outcome started a broadcast, its id and the reach counted
 * at the node — an estimate: the run fixes its audience when it starts.
 */
final readonly class AudienceBroadcastResult
{
    public function __construct(
        public AudienceBroadcastOutcome $outcome,
        public ?string $broadcastId = null,
        public int $recipientCount = 0,
    ) {
    }
}
