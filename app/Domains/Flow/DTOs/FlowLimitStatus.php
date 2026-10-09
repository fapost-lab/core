<?php

declare(strict_types=1);

namespace App\Domains\Flow\DTOs;

/**
 * Where the tenant stands against its flow limit, read once: how many flows it has, the cap (null: none) and
 * whether there is no room for one more.
 */
final readonly class FlowLimitStatus
{
    public function __construct(
        public int $current,
        public ?int $limit,
        public bool $reached,
    ) {
    }
}
