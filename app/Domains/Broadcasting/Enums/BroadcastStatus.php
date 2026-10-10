<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Enums;

/**
 * Lifecycle of a broadcast: Draft (editable) → Running (fan-out in progress) →
 * Completed. Failed is reserved for a run that could not resolve/start; Cancelled
 * stops an in-flight run (remaining recipients are skipped). A run the platform stopped
 * itself carries a `stop_reason` ({@see self::STOP_REASON_LIMIT_REACHED}).
 */
enum BroadcastStatus: string
{
    case Draft     = 'draft';
    case Running   = 'running';
    case Completed = 'completed';
    case Failed    = 'failed';
    case Cancelled = 'cancelled';
    /**
     * The tenant's outbound message volume for the period ran out mid-run.
     */
    public const string STOP_REASON_LIMIT_REACHED = 'limit_reached';

    public function isEditable(): bool
    {
        return self::Draft === $this;
    }
}
