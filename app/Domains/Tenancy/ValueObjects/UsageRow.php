<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\ValueObjects;

use App\Domains\Tenancy\Enums\UsageState;
use DateTimeImmutable;
use Fapost\Foundation\Quota\Enums\LimitKind;

/**
 * One limit on the tenant's usage page: what it is, how much is used, and where that stands.
 */
final readonly class UsageRow
{
    public function __construct(
        public string $key,
        /** The registry's English name; the page translates it. */
        public string $label,
        public LimitKind $kind,
        /** Records, per-period units or bytes; null when nobody reported it. */
        public ?int $used,
        /** Null means no limit, unless the state is unavailable. */
        public ?int $limit,
        /** Whole percent of the limit used, capped at 100; null without a limit or a count. */
        public ?int $percent,
        public UsageState $state,
        /** The end of the period (exclusive), for a per-period row the operator reported. */
        public ?DateTimeImmutable $periodEndsAt = null,
    ) {
    }
}
