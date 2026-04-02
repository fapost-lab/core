<?php

declare(strict_types=1);

namespace App\Domains\Contact\Enums;

enum PlatformEnum: string
{
    case Telegram = 'telegram';
    case WhatsApp = 'whatsapp';
    case Email    = 'email';
}
