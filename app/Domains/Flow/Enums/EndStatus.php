<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

/**
 * Terminal status emitted by an `end` node — drives `flow_sessions.end_status`,
 * subflow resume routing (success/cancelled/failed handles on the parent's
 * `subflow` node), and analytics event mapping.
 */
enum EndStatus: string
{
    case Success   = 'success';
    case Cancelled = 'cancelled';
    case Failed    = 'failed';

    /**
     * The end a subflow child that is over hands its parent: its `end` node's status when it recorded one, else failed
     * for a child that failed or that the subflow sweeper expired, cancelled for one that was cancelled or stopped by a
     * person, and success for one that ran off its graph. An `end_status` that is no case reads as success, as the
     * parent's `subflow` node routes it. Shared by the run recovery, which hands back only the children
     * {@see \App\Domains\Flow\Services\SegmentHandOff} counts as returned, and the run graph of the trace.
     */
    public static function handedBackBy(mixed $endStatus, ?FlowSessionStatus $status): self
    {
        if (is_string($endStatus) && '' !== $endStatus) {
            return self::tryFrom($endStatus) ?? self::Success;
        }

        return match ($status) {
            FlowSessionStatus::Failed, FlowSessionStatus::Expired             => self::Failed,
            FlowSessionStatus::Cancelled, FlowSessionStatus::TerminatedByUser => self::Cancelled,
            default                                                           => self::Success,
        };
    }
}
