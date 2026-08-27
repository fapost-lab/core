<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Enums;

/**
 * Runtime subsystem that produced a logged message.
 */
enum MessageOrigin: string
{
    case Flow      = 'flow';
    case Broadcast = 'broadcast';
    case Notify    = 'notify';
    case Command   = 'command';
    case System    = 'system';
    case Staff     = 'staff';
}
