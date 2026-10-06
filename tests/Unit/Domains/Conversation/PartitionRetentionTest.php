<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Conversation;

use App\Domains\Conversation\Retention\PartitionRetention;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class PartitionRetentionTest extends TestCase
{
    /**
     * @return array<string, array{mixed, ?int}>
     */
    public static function rawRetentionValues(): array
    {
        return [
            'plain string' => ['365', 365],
            'int'          => [30, 30],
            'one'          => ['1', 1],
            'months'       => ['12m', null],
            'year'         => ['1y', null],
            'words'        => ['6 months', null],
            'bool true'    => [true, null],
            'bool false'   => [false, null],
            'zero'         => ['0', null],
            'negative'     => ['-5', null],
            'empty'        => ['', null],
            'unset'        => [null, null],
            'float string' => ['1.5', null],
        ];
    }
    public function test_whole_months_older_than_the_cutoff_are_dropped_and_the_overlapping_month_is_kept(): void
    {
        $now = CarbonImmutable::parse('2026-03-15 12:00:00 UTC');

        $expired = (new PartitionRetention(30))->expired([
            'conversation_messages_2025_12',
            'conversation_messages_2026_01',
            'conversation_messages_2026_02',
            'conversation_messages_2026_03',
        ], $now);

        $this->assertSame(['conversation_messages_2025_12', 'conversation_messages_2026_01'], $expired);
    }

    public function test_the_current_month_is_never_dropped(): void
    {
        $now = CarbonImmutable::parse('2026-03-31 23:59:59 UTC');

        $this->assertFalse((new PartitionRetention(1))->isExpired('conversation_messages_2026_03', $now));
    }

    public function test_a_month_ending_exactly_at_the_cutoff_is_dropped(): void
    {
        // Cutoff = 2026-03-01 00:00:00; February ends exactly there.
        $now    = CarbonImmutable::parse('2026-03-31 00:00:00 UTC');
        $policy = new PartitionRetention(30);

        $this->assertTrue($policy->isExpired('conversation_messages_2026_02', $now));
    }

    public function test_a_month_ending_one_second_after_the_cutoff_is_kept(): void
    {
        // Cutoff = 2026-02-28 23:59:59, one second before February ends.
        $now = CarbonImmutable::parse('2026-03-30 23:59:59 UTC');

        $this->assertFalse((new PartitionRetention(30))->isExpired('conversation_messages_2026_02', $now));
    }

    public function test_foreign_names_are_never_expired(): void
    {
        $now = CarbonImmutable::parse('2030-01-01 00:00:00 UTC');

        $this->assertSame([], (new PartitionRetention(1))->expired([
            'flow_logs_2020_01',
            'conversation_messages_default',
            'conversation_messages_2020_01; DROP TABLE users',
        ], $now));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rawRetentionValues')]
    public function test_parse_days_accepts_only_a_plain_positive_whole_number(mixed $raw, ?int $expected): void
    {
        $this->assertSame($expected, PartitionRetention::parseDays($raw));
    }
}
