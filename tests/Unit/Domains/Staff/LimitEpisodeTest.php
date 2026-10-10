<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Staff;

use App\Domains\Staff\Support\LimitEpisode;
use App\Domains\Tenancy\Enums\RefusedWork;
use App\Domains\Tenancy\Events\LimitReached;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Fapost\Foundation\Quota\Enums\LimitKind;
use PHPUnit\Framework\TestCase;

final class LimitEpisodeTest extends TestCase
{
    private const string TENANT = '01k00000000000000000000000';

    public function test_a_records_limit_is_one_episode_per_limit_value_for_seven_days(): void
    {
        $at = new DateTimeImmutable('2026-10-10 12:00:00 UTC');

        $three = LimitEpisode::of($this->event(LimitKind::Records, 3, $at));
        $same  = LimitEpisode::of($this->event(LimitKind::Records, 3, $at->modify('+2 days')));
        $four  = LimitEpisode::of($this->event(LimitKind::Records, 4, $at));

        $this->assertSame($three->cacheKey, $same->cacheKey);
        $this->assertNotSame($three->cacheKey, $four->cacheKey);
        $this->assertSame('limit_notice:' . self::TENANT . ':assistants:l3', $three->cacheKey);
        $this->assertEquals(CarbonImmutable::parse('2026-10-17 12:00:00 UTC'), $three->expiresAt);
    }

    public function test_a_bytes_limit_follows_the_same_rule(): void
    {
        $episode = LimitEpisode::of($this->event(LimitKind::Bytes, 1_000, new DateTimeImmutable('2026-10-10 12:00:00 UTC')));

        $this->assertStringEndsWith(':l1000', $episode->cacheKey);
    }

    public function test_a_per_period_limit_uses_the_period_the_operator_reported(): void
    {
        $at  = new DateTimeImmutable('2026-10-10 12:00:00 UTC');
        $end = new DateTimeImmutable('2026-11-05 00:00:00 UTC');

        $episode = LimitEpisode::of($this->event(LimitKind::PerPeriod, 500, $at, $end));
        $later   = LimitEpisode::of($this->event(LimitKind::PerPeriod, 500, $at->modify('+3 days'), $end));
        $next    = LimitEpisode::of($this->event(LimitKind::PerPeriod, 500, $at->modify('+30 days'), new DateTimeImmutable('2026-12-05 00:00:00 UTC')));

        $this->assertSame('limit_notice:' . self::TENANT . ':assistants:p' . $end->getTimestamp(), $episode->cacheKey);
        $this->assertSame($episode->cacheKey, $later->cacheKey);
        $this->assertNotSame($episode->cacheKey, $next->cacheKey);
        $this->assertEquals(CarbonImmutable::parse('2026-11-05 01:00:00 UTC'), $episode->expiresAt);
    }

    public function test_a_long_operator_period_is_remembered_to_its_end_and_no_longer_than_400_days(): void
    {
        $at     = new DateTimeImmutable('2026-10-10 12:00:00 UTC');
        $yearly = LimitEpisode::of($this->event(LimitKind::PerPeriod, 500, $at, new DateTimeImmutable('2027-10-10 00:00:00 UTC')));
        $absurd = LimitEpisode::of($this->event(LimitKind::PerPeriod, 500, $at, new DateTimeImmutable('2040-01-01 00:00:00 UTC')));

        $this->assertEquals(CarbonImmutable::parse('2027-10-10 01:00:00 UTC'), $yearly->expiresAt);
        $this->assertEquals($at->modify('+400 days'), $absurd->expiresAt);
    }

    public function test_a_per_period_limit_without_a_period_is_the_calendar_month_in_utc(): void
    {
        $october = LimitEpisode::of($this->event(LimitKind::PerPeriod, 500, new DateTimeImmutable('2026-10-31 23:30:00 UTC')));
        $same    = LimitEpisode::of($this->event(LimitKind::PerPeriod, 500, new DateTimeImmutable('2026-10-01 00:00:00 UTC')));
        $next    = LimitEpisode::of($this->event(LimitKind::PerPeriod, 500, new DateTimeImmutable('2026-11-01 00:00:00 UTC')));

        $this->assertSame('limit_notice:' . self::TENANT . ':assistants:m2026-10', $october->cacheKey);
        $this->assertSame($october->cacheKey, $same->cacheKey);
        $this->assertNotSame($october->cacheKey, $next->cacheKey);
    }

    public function test_a_period_that_has_already_ended_falls_back_to_the_month(): void
    {
        $at      = new DateTimeImmutable('2026-10-10 12:00:00 UTC');
        $episode = LimitEpisode::of($this->event(LimitKind::PerPeriod, 500, $at, new DateTimeImmutable('2026-10-01 00:00:00 UTC')));

        $this->assertStringEndsWith(':m2026-10', $episode->cacheKey);
    }

    public function test_the_tenant_and_the_key_are_part_of_the_episode(): void
    {
        $at = new DateTimeImmutable('2026-10-10 12:00:00 UTC');

        $mine  = LimitEpisode::of($this->event(LimitKind::Records, 3, $at));
        $other = LimitEpisode::of(new LimitReached('01k99999999999999999999999', 'assistants', LimitKind::Records, 3, 3, null, $at));

        $this->assertNotSame($mine->cacheKey, $other->cacheKey);
    }

    private function event(LimitKind $kind, int $limit, DateTimeImmutable $at, ?DateTimeImmutable $periodEndsAt = null): LimitReached
    {
        return new LimitReached(self::TENANT, 'assistants', $kind, $limit, $limit, RefusedWork::Other, $at, $periodEndsAt);
    }
}
