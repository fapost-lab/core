<?php

declare(strict_types=1);

namespace App\Domains\Presale\Enums;

enum MessengerPreference: string
{
    case Telegram = 'telegram';
    case WhatsApp = 'whatsapp';
    case Both     = 'both';
}
