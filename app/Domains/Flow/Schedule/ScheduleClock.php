<?php

declare(strict_types=1);

namespace App\Domains\Flow\Schedule;

use Carbon\CarbonImmutable;
use Cron\AbstractField;
use Cron\CronExpression;
use Cron\HoursField;
use Cron\MinutesField;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;

/**
 * The slots of a schedule trigger: the moments its cron expression describes in its time zone, as UTC minutes.
 *
 * The cron library walks local wall-clock time and gets daylight saving transitions wrong for a schedule: a local time
 * that does not exist (spring forward) comes back shifted to the next hour, and a local time that happens twice (fall
 * back) comes back twice. A candidate is therefore taken only when its own local time matches the expression (the
 * shifted one does not, so a daily `30 2 * * *` does not fire on the day 02:30 is skipped) and when it is the first
 * occurrence of that local time (an hourly schedule fires 02:00 once on the day 02:00 happens twice).
 *
 * A slot is identified by its UTC minute ({@see self::fireKey()}), never by its local time, which is ambiguous.
 */
final readonly class ScheduleClock
{
    /** How many library candidates are looked at before a schedule is taken to have no next slot. */
    private const int MAX_CANDIDATES = 1000;

    /** The largest daylight saving shift there is, in seconds; a repeated local time lies at most this far back. */
    private const int MAX_SHIFT_SECONDS = 7200;

    /**
     * The fire key of a slot: `schedule:` and its UTC minute, such as `schedule:2026-11-20T09:00Z`.
     */
    public static function fireKey(DateTimeInterface $slot): string
    {
        return 'schedule:' . CarbonImmutable::createFromTimestampUTC($slot->getTimestamp())->format('Y-m-d\TH:i\Z');
    }

    /**
     * The first slot strictly after {@code $after}, as a UTC minute; null when the expression never matches (such as
     * the 30th of February).
     */
    public function nextSlotAfter(ScheduleTriggerConfig $config, DateTimeInterface $after): ?CarbonImmutable
    {
        $zone   = new DateTimeZone($config->timezone);
        $cron   = new CronExpression($config->cron);
        $cursor = DateTimeImmutable::createFromInterface($after)->setTimezone($zone);

        for ($attempt = 0; $attempt < self::MAX_CANDIDATES; ++$attempt) {
            try {
                $candidate = DateTimeImmutable::createFromMutable($cron->getNextRunDate($cursor, 0, false, $config->timezone));
            } catch (RuntimeException) {
                return null;
            }

            if ($candidate <= $cursor) {
                // Never stand still: a library answer at or before the cursor would repeat for ever.
                $candidate = $cursor->modify('+1 minute');
            }

            $cursor = $candidate;

            if ($this->wallClockMatches($cron, $candidate->setTimezone($zone)) && ! $this->repeatsEarlierLocalTime($candidate, $zone)) {
                return CarbonImmutable::createFromTimestamp($candidate->getTimestamp(), 'UTC')->startOfMinute();
            }
        }

        return null;
    }

    /**
     * Whether the local minute and hour of {@code $local} are the ones the expression names. A local time that does
     * not exist comes back from the library moved forward by the transition, so its hour (or, for a half-hour shift,
     * its minute) no longer matches; the library's own `isDue()` and `HoursField` accept the moved hour on purpose,
     * so the fields are matched here. A transition never changes the day the library chose.
     */
    private function wallClockMatches(CronExpression $cron, DateTimeImmutable $local): bool
    {
        return $this->fieldMatches(new MinutesField(), (string) $cron->getExpression(CronExpression::MINUTE), (int) $local->format('i'))
            && $this->fieldMatches(new HoursField(), (string) $cron->getExpression(CronExpression::HOUR), (int) $local->format('G'));
    }

    private function fieldMatches(AbstractField $field, string $expression, int $value): bool
    {
        foreach (explode(',', $expression) as $piece) {
            if ($field->isSatisfied($value, $piece)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the local wall-clock minute of {@code $candidate} already happened earlier, at another offset — the
     * second pass through the hour a fall-back transition repeats.
     */
    private function repeatsEarlierLocalTime(DateTimeImmutable $candidate, DateTimeZone $zone): bool
    {
        $wallClock = $candidate->setTimezone($zone)->format('Y-m-d H:i');

        for ($back = 1800; $back <= self::MAX_SHIFT_SECONDS; $back += 1800) {
            $earlier = $candidate->setTimestamp($candidate->getTimestamp() - $back)->setTimezone($zone);

            if ($earlier->format('Y-m-d H:i') === $wallClock) {
                return true;
            }
        }

        return false;
    }
}
