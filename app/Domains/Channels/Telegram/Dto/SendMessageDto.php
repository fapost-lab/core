<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram\Dto;

use Spatie\LaravelData\Data;

final class SendMessageDto extends Data
{
    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function __construct(
        public readonly string $chatId,
        public readonly string $text,
        public readonly ?string $parseMode = null,
        public readonly ?array $replyMarkup = null,
    ) {
    }
}
