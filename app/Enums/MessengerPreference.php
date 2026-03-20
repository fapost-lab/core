<?php

declare(strict_types=1);

namespace App\Enums;

enum MessengerPreference: string
{
    case Telegram = 'telegram';
    case WhatsApp = 'whatsapp';
    case Both     = 'both';
}
