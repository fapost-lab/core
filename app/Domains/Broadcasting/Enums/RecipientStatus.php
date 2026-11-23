<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Enums;

use LogicException;

/**
 * Per-recipient delivery bookkeeping within a broadcast run.
 */
enum RecipientStatus: string
{
    case Pending = 'pending';
    case Sent    = 'sent';
    case Failed  = 'failed';
    case Skipped = 'skipped';

    /**
     * The broadcast counter a recipient settled with this status is counted in. A pending recipient is not settled,
     * so it has none.
     *
     * @return 'sent_count'|'failed_count'|'skipped_count'
     *
     * @throws LogicException for {@see self::Pending}
     */
    public function counterColumn(): string
    {
        return match ($this) {
            self::Sent    => 'sent_count',
            self::Failed  => 'failed_count',
            self::Skipped => 'skipped_count',
            self::Pending => throw new LogicException('A pending recipient has no broadcast counter; it cannot be settled.'),
        };
    }
}
