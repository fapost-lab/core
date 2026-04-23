<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram\Dto;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
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
