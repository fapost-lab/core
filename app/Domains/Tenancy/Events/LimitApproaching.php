<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Events;

use DateTimeImmutable;

/**
 * A tenant's per-period limit is close to being used up: the operator's usage meter flagged an
 * allowed decision as approaching. Nothing was refused.
 *
 * Dispatched only by {@see \App\Domains\Tenancy\Services\LimitAnnouncer}, which `PeriodQuota` calls;
 * a listener reacts synchronously and must stay cheap, the dispatching path is a hot one. Not queued
 * and not broadcast. Always a per-period limit: Core sets no threshold for records or bytes.
 */
final readonly class LimitApproaching
{
    /**
     * @param  DateTimeImmutable|null  $periodEndsAt  when the period ends, when the operator reports it
     */
    public function __construct(
        public string $tenantId,
        public string $key,
        public int $limit,
        public int $used,
        public DateTimeImmutable $occurredAt,
        public ?DateTimeImmutable $periodEndsAt = null,
    ) {
    }
}
