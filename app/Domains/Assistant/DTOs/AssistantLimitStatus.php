<?php

declare(strict_types=1);

namespace App\Domains\Assistant\DTOs;

/**
 * Where the tenant stands against its assistant limit, read once: how many assistants it has, the cap (null: none)
 * and whether there is no room for one more.
 */
final readonly class AssistantLimitStatus
{
    public function __construct(
        public int $current,
        public ?int $limit,
        public bool $reached,
    ) {
    }
}
