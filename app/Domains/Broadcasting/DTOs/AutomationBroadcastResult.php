<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\DTOs;

use App\Domains\Broadcasting\Enums\AutomationBroadcastOutcome;

/**
 * The answer to an {@see AutomationBroadcastRequest}: the outcome and, when it names a broadcast, its id and the reach
 * counted when the request was answered. The run itself fixes its audience later, when `RunBroadcastJob` resolves it.
 */
final readonly class AutomationBroadcastResult
{
    public function __construct(
        public AutomationBroadcastOutcome $outcome,
        public ?string $broadcastId = null,
        public int $recipientCount = 0,
    ) {
    }
}
