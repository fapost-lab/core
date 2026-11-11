<?php

declare(strict_types=1);

namespace App\Domains\Audit\Enums;

/**
 * The area an audit log entry belongs to, stored as the entry's `log_name`; the screen filters by it.
 */
enum AuditArea: string
{
    case Assistants = 'assistants';
    case Channels   = 'channels';
    case Flows      = 'flows';
    case Broadcasts = 'broadcasts';
    case Contacts   = 'contacts';
    case Staff      = 'staff';
    case Settings   = 'settings';
    case Auth       = 'auth';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $area): string => $area->value, self::cases());
    }
}
