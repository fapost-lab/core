<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Enums;

/**
 * Lifecycle of a broadcast: Draft (editable) → Running (fan-out in progress) →
 * Completed. Failed is a run whose launching job crashed (`stop_reason`
 * {@see self::STOP_REASON_RUN_FAILED}); its still-pending recipients are skipped. Cancelled
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

    /**
     * The launching job failed (audience resolution or fan-out threw) after exhausting its attempts.
     */
    public const string STOP_REASON_RUN_FAILED = 'run_failed';

    public function isEditable(): bool
    {
        return self::Draft === $this;
    }
}
