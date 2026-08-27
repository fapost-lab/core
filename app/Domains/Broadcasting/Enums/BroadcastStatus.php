<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Enums;

/**
 * Lifecycle of a broadcast: Draft (editable) → Running (fan-out in progress) →
 * Completed. Failed is reserved for a run that could not resolve/start; Cancelled
 * stops an in-flight run (remaining recipients are skipped).
 */
enum BroadcastStatus: string
{
    case Draft     = 'draft';
    case Running   = 'running';
    case Completed = 'completed';
    case Failed    = 'failed';
    case Cancelled = 'cancelled';

    public function isEditable(): bool
    {
        return self::Draft === $this;
    }
}
