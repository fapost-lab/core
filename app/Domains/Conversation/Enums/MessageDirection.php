<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Enums;

/**
 * Direction of a logged conversation message relative to the contact.
 */
enum MessageDirection: string
{
    case Inbound  = 'inbound';
    case Outbound = 'outbound';
}
