<?php

declare(strict_types=1);

namespace App\Domains\Channels\DTOs;

/**
 * Where the tenant stands against its channel limit, read once: how many channels it has, the cap (null: none) and
 * whether there is no room for one more.
 */
final readonly class ChannelLimitStatus
{
    public function __construct(
        public int $current,
        public ?int $limit,
        public bool $reached,
    ) {
    }
}
