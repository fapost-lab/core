<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;

/**
 * How a run a webhook started ended, as its waiting sender is told when no `webhook_response` node answered first.
 */
enum WebhookRunEnd: string
{
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed    = 'failed';

    /**
     * The end a root session's status says, or null while the run has not ended — or ended some other way (expired,
     * cancelled from outside, terminated), when the sender is left to its 202.
     */
    public static function of(FlowSessionStatus $status, ?string $endStatus): ?self
    {
        return match ($status) {
            FlowSessionStatus::Completed => self::Completed,
            FlowSessionStatus::Failed    => self::Failed,
            FlowSessionStatus::Ended     => match (EndStatus::tryFrom((string) $endStatus)) {
                EndStatus::Failed    => self::Failed,
                EndStatus::Cancelled => self::Cancelled,
                default              => self::Completed,
            },
            default => null,
        };
    }
}
