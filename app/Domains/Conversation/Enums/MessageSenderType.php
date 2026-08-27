<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Enums;

/**
 * Who authored a logged message. `Staff` is reserved for future human takeover.
 */
enum MessageSenderType: string
{
    case Contact   = 'contact';
    case Assistant = 'assistant';
    case Staff     = 'staff';
    case System    = 'system';
}
