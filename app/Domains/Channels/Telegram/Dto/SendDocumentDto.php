<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram\Dto;

use Spatie\LaravelData\Data;

final class SendDocumentDto extends Data
{
    public function __construct(
        public readonly string $chatId,
        public readonly string $document,
        public readonly ?string $caption = null,
    ) {
    }
}
