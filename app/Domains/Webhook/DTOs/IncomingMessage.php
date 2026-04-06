<?php

declare(strict_types=1);

namespace App\Domains\Webhook\DTOs;

use App\Domains\Contact\Enums\PlatformEnum;
use Spatie\LaravelData\Data;

final class IncomingMessage extends Data
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $updateId,
        public readonly string $externalChatId,
        public readonly string $externalUserId,
        public readonly ?string $text,
        public readonly PlatformEnum $platform,
        public readonly string $messageType,
        public readonly int $timestamp,
        public readonly array $meta = [],
    ) {
    }
}
