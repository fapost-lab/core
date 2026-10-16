<?php

declare(strict_types=1);

namespace App\Domains\Staff\Support;

use App\Domains\Tenancy\Events\LimitApproaching;
use App\Domains\Tenancy\Events\LimitReached;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Quota\Enums\LimitKind;

/**
 * The stretch of time within which a tenant's admins hear about one limit once.
 *
 * - A per-period limit: the operator's period when the refusal carried its end, else the calendar
 *   month in UTC.
 * - A warning that a per-period limit is close ({@see LimitApproaching}): the same period, under its own
 *   key (`ap…` / `am…` against `p…` / `m…`), so a warning never silences the notice that the limit is
 *   used up in the same period.
 * - A records or bytes limit: the limit value, so raising it and hitting the new one speaks again at
 *   once, with a reminder at most every {@see self::REPEAT_DAYS} days while refusals go on.
 */
final readonly class LimitEpisode
{
    public const int REPEAT_DAYS = 7;

    /** Longest a per-period episode is remembered, whatever end the operator reports (an annual plan fits). */
    private const int MAX_PERIOD_DAYS = 400;

    private function __construct(
        public string $cacheKey,
        public CarbonImmutable $expiresAt,
    ) {
    }

    public static function of(LimitReached|LimitApproaching $event): self
    {
        $prefix     = sprintf('limit_notice:%s:%s:', $event->tenantId, $event->key);
        $occurredAt = CarbonImmutable::instance($event->occurredAt)->utc();
        $warning    = $event instanceof LimitApproaching ? 'a' : '';

        if ($event instanceof LimitReached && LimitKind::PerPeriod !== $event->kind) {
            return new self($prefix . 'l' . $event->limit, $occurredAt->addDays(self::REPEAT_DAYS));
        }

        if (null !== $event->periodEndsAt && $event->periodEndsAt > $occurredAt) {
            $end     = CarbonImmutable::instance($event->periodEndsAt);
            $expires = $end->addHour()->min($occurredAt->addDays(self::MAX_PERIOD_DAYS));

            return new self($prefix . $warning . 'p' . $end->getTimestamp(), $expires);
        }

        return new self($prefix . $warning . 'm' . $occurredAt->format('Y-m'), $occurredAt->endOfMonth()->addHour());
    }
}
