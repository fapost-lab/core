<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Schedule;

use App\Domains\Flow\Schedule\InvalidScheduleConfigException;
use App\Domains\Flow\Schedule\ScheduleClock;
use App\Domains\Flow\Schedule\ScheduleTriggerConfig;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The slots of a schedule as UTC minutes, across daylight saving transitions: a local time that does not exist is
 * skipped, one that happens twice fires once.
 */
final class ScheduleClockTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function invalidCrons(): array
    {
        return [
            'six fields (seconds)' => ['* * * * * *'],
            'four fields'          => ['* * * *'],
            'empty'                => [''],
            'out of range'         => ['61 * * * *'],
            'unknown macro'        => ['@reboot'],
            'not cron'             => ['every day'],
        ];
    }

    public function test_an_ordinary_schedule_walks_its_slots_in_utc(): void
    {
        $this->assertSame(
            ['2026-11-20T08:00:00+00:00', '2026-11-23T08:00:00+00:00', '2026-11-24T08:00:00+00:00'],
            $this->slots('0 9 * * 1-5', 'Europe/Berlin', '2026-11-20T07:30:00Z', 3),
        );
    }

    public function test_a_slot_is_strictly_after_the_moment_given(): void
    {
        $this->assertSame(['2026-11-20T09:01:00+00:00'], $this->slots('* * * * *', 'UTC', '2026-11-20T09:00:00Z', 1));
        $this->assertSame(['2026-11-20T09:01:00+00:00'], $this->slots('* * * * *', 'UTC', '2026-11-20T09:00:30Z', 1));
    }

    public function test_a_local_time_skipped_in_spring_does_not_fire_that_day(): void
    {
        // Europe/Berlin, 29 March 2026: 02:00 CET jumps to 03:00 CEST, so 02:30 does not exist.
        $this->assertSame(
            ['2026-03-28T01:30:00+00:00', '2026-03-30T00:30:00+00:00'],
            $this->slots('30 2 * * *', 'Europe/Berlin', '2026-03-28T00:00:00Z', 2),
        );
    }

    public function test_a_local_time_repeated_in_autumn_fires_once(): void
    {
        // Europe/Berlin, 25 October 2026: 03:00 CEST falls back to 02:00 CET, so 02:30 happens twice.
        $this->assertSame(
            ['2026-10-24T00:30:00+00:00', '2026-10-25T00:30:00+00:00', '2026-10-26T01:30:00+00:00'],
            $this->slots('30 2 * * *', 'Europe/Berlin', '2026-10-24T00:00:00Z', 3),
        );
    }

    public function test_an_hourly_schedule_fires_the_repeated_hour_once(): void
    {
        $this->assertSame(
            ['2026-10-25T00:00:00+00:00', '2026-10-25T02:00:00+00:00', '2026-10-25T03:00:00+00:00'],
            $this->slots('0 * * * *', 'Europe/Berlin', '2026-10-24T23:30:00Z', 3),
            '02:00 CEST (00:00Z) fires; 02:00 CET (01:00Z) is the same local time again and does not.',
        );
    }

    public function test_a_half_hour_transition_repeats_half_an_hour_once(): void
    {
        // Australia/Lord_Howe, 5 April 2026: 02:00 +11:00 falls back to 01:30 +10:30, so 01:30 happens twice.
        $this->assertSame(
            ['2026-04-04T14:00:00+00:00', '2026-04-04T14:30:00+00:00', '2026-04-05T14:30:00+00:00'],
            $this->slots('*/30 1 * * *', 'Australia/Lord_Howe', '2026-04-04T12:00:00Z', 3),
        );
    }

    public function test_a_schedule_that_never_matches_has_no_slot(): void
    {
        $this->assertNull((new ScheduleClock())->nextSlotAfter($this->config('0 0 30 2 *', 'UTC'), new DateTimeImmutable('2026-01-01T00:00:00Z')));
    }

    public function test_the_fire_key_is_the_utc_minute_of_the_slot(): void
    {
        $this->assertSame('schedule:2026-11-20T09:00Z', ScheduleClock::fireKey(new DateTimeImmutable('2026-11-20T10:00:00+01:00')));
    }

    public function test_a_macro_is_five_fields(): void
    {
        $this->assertSame(['2026-01-02T00:00:00+00:00'], $this->slots('@daily', 'UTC', '2026-01-01T00:00:30Z', 1));
    }

    public function test_the_historical_target_key_is_the_time_zone(): void
    {
        $this->assertSame('Europe/Berlin', ScheduleTriggerConfig::fromConfig(['cron' => '0 9 * * *', 'target' => 'Europe/Berlin'])->timezone);
    }

    #[DataProvider('invalidCrons')]
    public function test_a_cron_that_is_not_five_fields_is_refused(string $cron): void
    {
        try {
            ScheduleTriggerConfig::fromConfig(['cron' => $cron, 'timezone' => 'UTC']);
        } catch (InvalidScheduleConfigException $exception) {
            $this->assertSame(InvalidScheduleConfigException::INVALID_CRON, $exception->errorCode);

            return;
        }

        $this->fail("'{$cron}' should be refused.");
    }

    public function test_an_unknown_time_zone_is_refused(): void
    {
        foreach (['Mars/Olympus', '', '+02:00'] as $timezone) {
            try {
                ScheduleTriggerConfig::fromConfig(['cron' => '0 9 * * *', 'timezone' => $timezone]);
                $this->fail("'{$timezone}' should be refused.");
            } catch (InvalidScheduleConfigException $exception) {
                $this->assertSame(InvalidScheduleConfigException::INVALID_TIMEZONE, $exception->errorCode);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function slots(string $cron, string $timezone, string $from, int $count): array
    {
        $clock  = new ScheduleClock();
        $config = $this->config($cron, $timezone);
        $cursor = new DateTimeImmutable($from);
        $slots  = [];

        for ($i = 0; $i < $count; ++$i) {
            $cursor = $clock->nextSlotAfter($config, $cursor);
            $this->assertNotNull($cursor);
            $this->assertSame('UTC', $cursor->getTimezone()->getName());
            $slots[] = $cursor->setTimezone(new DateTimeZone('UTC'))->format('c');
        }

        return $slots;
    }

    private function config(string $cron, string $timezone): ScheduleTriggerConfig
    {
        return ScheduleTriggerConfig::fromConfig(['cron' => $cron, 'timezone' => $timezone]);
    }
}
