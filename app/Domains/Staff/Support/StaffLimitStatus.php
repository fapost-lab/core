<?php

declare(strict_types=1);

namespace App\Domains\Staff\Support;

/**
 * The tenant's staff limit as it stood when it was read: how many places are taken, the limit (null for none) and
 * whether there is no room for one more account.
 */
final readonly class StaffLimitStatus
{
    public function __construct(
        public int $current,
        public ?int $limit,
        public bool $reached,
    ) {
    }
}
