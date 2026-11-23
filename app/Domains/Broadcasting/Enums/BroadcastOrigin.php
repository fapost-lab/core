<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Enums;

/**
 * Who started a broadcast. `Console` is a person on the broadcasts screen, who confirmed the revision they saw;
 * `Automation` is a flow's `send_message` node, started through {@see \App\Domains\Broadcasting\Services\AutomationBroadcasts}
 * on the flow's published definition.
 */
enum BroadcastOrigin: string
{
    case Console    = 'console';
    case Automation = 'automation';
}
