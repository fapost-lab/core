<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

enum SendMessageContentType: string
{
    case Text             = 'text';
    case TextWithKeyboard = 'text_with_keyboard';
    case Image            = 'image';
    case Document         = 'document';
    case Video            = 'video';
    case Voice            = 'voice';

    public function requiresMediaUrl(): bool
    {
        return match ($this) {
            self::Image, self::Document, self::Video, self::Voice => true,
            self::Text, self::TextWithKeyboard                    => false,
        };
    }
}
