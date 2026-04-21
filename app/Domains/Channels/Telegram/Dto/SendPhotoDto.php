<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram\Dto;

use Spatie\LaravelData\Data;

final class SendPhotoDto extends Data
{
    public function __construct(
        public readonly string $chatId,
        public readonly string $photo,
        public readonly ?string $caption = null,
    ) {
    }
}
