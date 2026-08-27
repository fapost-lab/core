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
}
