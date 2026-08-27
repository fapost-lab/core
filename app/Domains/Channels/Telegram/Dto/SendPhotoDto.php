<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram\Dto;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class SendPhotoDto extends Data
{
    public function __construct(
        public readonly string $chatId,
        public readonly string $photo,
        public readonly ?string $caption = null,
    ) {
    }
}
