<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Enums;

/**
 * Per-recipient delivery bookkeeping within a broadcast run.
 */
enum RecipientStatus: string
{
    case Pending = 'pending';
    case Sent    = 'sent';
    case Failed  = 'failed';
    case Skipped = 'skipped';
}
