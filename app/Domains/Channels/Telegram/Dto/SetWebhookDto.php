<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram\Dto;

use Spatie\LaravelData\Data;

final class SetWebhookDto extends Data
{
    /**
     * @param  list<string>|null  $allowedUpdates
     */
    public function __construct(
        public readonly string $url,
        public readonly string $secretToken,
        public readonly ?array $allowedUpdates = null,
    ) {
    }
}
