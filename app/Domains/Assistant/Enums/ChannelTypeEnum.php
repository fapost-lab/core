<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Enums;

enum ChannelTypeEnum: string
{
    case Telegram = 'telegram';

    case WhatsApp = 'whatsapp';
}
