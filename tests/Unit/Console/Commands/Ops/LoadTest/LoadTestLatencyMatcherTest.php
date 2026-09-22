<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Ops\LoadTest;

use App\Console\Commands\Ops\LoadTest\Support\LoadTestLatencyMatcher;
use Tests\TestCase;

final class LoadTestLatencyMatcherTest extends TestCase
{
    private const string PREFIX = 'Load test accepted: ';

    private LoadTestLatencyMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = new LoadTestLatencyMatcher();
    }

    public function test_matches_the_earliest_confirm_at_or_after_the_send_time(): void
    {
        $sentAt  = [1 => 100.0];
        $entries = [
            ['chat_id' => 1, 'text' => self::PREFIX . 'CODE', 'ts' => 100.5],
            ['chat_id' => 1, 'text' => self::PREFIX . 'CODE', 'ts' => 101.5], // later duplicate, ignored
        ];

        $latencies = $this->matcher->match($sentAt, $entries, self::PREFIX);

        self::assertSame([0.5], $latencies);
    }

    public function test_ignores_confirms_that_happened_before_the_send(): void
    {
        // A stale confirm from a previous round must not produce a negative
        // or otherwise meaningless latency.
        $sentAt  = [1 => 100.0];
        $entries = [
            ['chat_id' => 1, 'text' => self::PREFIX . 'CODE', 'ts' => 99.0],
        ];

        self::assertSame([], $this->matcher->match($sentAt, $entries, self::PREFIX));
    }

    public function test_ignores_entries_for_other_chats_and_non_confirm_text(): void
    {
        $sentAt  = [1 => 100.0];
        $entries = [
            ['chat_id' => 2, 'text' => self::PREFIX . 'CODE', 'ts' => 100.2],
            ['chat_id' => 1, 'text' => 'Load test: reply with your code.', 'ts' => 100.1],
        ];

        self::assertSame([], $this->matcher->match($sentAt, $entries, self::PREFIX));
    }

    public function test_percentile_returns_null_for_empty_sample(): void
    {
        self::assertNull($this->matcher->percentile([], 50));
    }

    public function test_percentile_p50_and_p95_over_a_sorted_sample(): void
    {
        $values = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0];

        self::assertSame(5.0, $this->matcher->percentile($values, 50));
        self::assertSame(10.0, $this->matcher->percentile($values, 95));
    }

    public function test_percentile_does_not_mutate_the_input_array_order(): void
    {
        $values = [3.0, 1.0, 2.0];

        $this->matcher->percentile($values, 50);

        self::assertSame([3.0, 1.0, 2.0], $values);
    }
}
