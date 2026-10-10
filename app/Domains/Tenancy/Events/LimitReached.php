<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Events;

use App\Domains\Tenancy\Enums\RefusedWork;
use DateTimeImmutable;
use Fapost\Foundation\Quota\Enums\LimitKind;

/**
 * A tenant's limit has been used up: it refused work, or (records only) the record that took the last
 * place has just been saved.
 *
 * Dispatched only by {@see \App\Domains\Tenancy\Services\LimitAnnouncer}, which `RecordQuota`,
 * `PeriodQuota` and `MediaStorageGate` call; a listener reacts synchronously and must stay cheap, the
 * dispatching path is a hot one. Not queued and not broadcast.
 */
final readonly class LimitReached
{
    /**
     * @param  int|null  $used  how much is used; null when the operator did not say
     * @param  RefusedWork|null  $refused  what was turned away; null when the limit was only reached
     * @param  DateTimeImmutable|null  $periodEndsAt  per-period limits only, when the operator reports it
     */
    public function __construct(
        public string $tenantId,
        public string $key,
        public LimitKind $kind,
        public int $limit,
        public ?int $used,
        public ?RefusedWork $refused,
        public DateTimeImmutable $occurredAt,
        public ?DateTimeImmutable $periodEndsAt = null,
    ) {
    }
}
