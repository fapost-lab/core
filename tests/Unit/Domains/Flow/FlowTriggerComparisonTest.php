<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Support\FlowTriggerComparison;
use PHPUnit\Framework\TestCase;

final class FlowTriggerComparisonTest extends TestCase
{
    private const string FIRST = '0b0e6c0e-6a7e-4a43-9c53-0f2b6d6a9d11';

    private const string SECOND = '5d1f0c2a-7b3e-4c8d-9e2f-1a2b3c4d5e6f';

    public function test_same_config_ignores_the_order_of_object_keys_at_every_level(): void
    {
        $left  = ['cron' => '0 9 * * *', 'timezone' => 'UTC', 'extra' => ['b' => 1, 'a' => ['y' => 2, 'x' => 3]]];
        $right = ['extra' => ['a' => ['x' => 3, 'y' => 2], 'b' => 1], 'timezone' => 'UTC', 'cron' => '0 9 * * *'];

        $this->assertTrue(FlowTriggerComparison::sameConfig($left, $right));
    }

    public function test_same_config_keeps_the_order_of_a_list(): void
    {
        $this->assertFalse(FlowTriggerComparison::sameConfig(['keywords' => ['a', 'b']], ['keywords' => ['b', 'a']]));
        $this->assertFalse(FlowTriggerComparison::sameConfig(['nested' => [['k' => 1], ['k' => 2]]], ['nested' => [['k' => 2], ['k' => 1]]]));
    }

    public function test_same_config_tells_different_values_and_keys_apart(): void
    {
        $this->assertFalse(FlowTriggerComparison::sameConfig(['a' => 1], ['a' => 2]));
        $this->assertFalse(FlowTriggerComparison::sameConfig(['a' => 1], ['a' => 1, 'b' => null]));
        $this->assertTrue(FlowTriggerComparison::sameConfig([], []));
    }

    public function test_diff_of_equal_lists_is_empty(): void
    {
        $list = [self::trigger(self::FIRST), self::trigger(self::SECOND, 'event')];

        $this->assertSame(['added' => [], 'changed' => [], 'removed' => []], FlowTriggerComparison::diff($list, $list));
    }

    public function test_diff_reports_an_added_and_a_removed_trigger(): void
    {
        $kept    = self::trigger(self::FIRST);
        $removed = self::trigger(self::SECOND, 'event');
        $added   = self::trigger('9f8e7d6c-5b4a-4321-8fed-cba987654321', 'schedule');

        $diff = FlowTriggerComparison::diff([$kept, $removed], [$kept, $added]);

        $this->assertSame([$added], $diff['added']);
        $this->assertSame([$removed], $diff['removed']);
        $this->assertSame([], $diff['changed']);
    }

    public function test_diff_does_not_count_a_config_whose_keys_were_reordered(): void
    {
        $before = self::trigger(self::FIRST, 'schedule', config: ['cron' => '0 9 * * *', 'timezone' => 'UTC']);
        $after  = self::trigger(self::FIRST, 'schedule', config: ['timezone' => 'UTC', 'cron' => '0 9 * * *']);

        $this->assertSame([], FlowTriggerComparison::diff([$before], [$after])['changed']);
    }

    public function test_diff_counts_a_changed_priority_flag_type_or_config(): void
    {
        $before = self::trigger(self::FIRST);

        foreach ([
            ['priority' => 5],
            ['is_active' => false],
            ['type' => 'event'],
            ['config' => ['keywords' => ['hi']]],
        ] as $override) {
            $after = [...$before, ...$override];

            $this->assertSame(
                [['before' => $before, 'after' => $after]],
                FlowTriggerComparison::diff([$before], [$after])['changed'],
            );
        }
    }

    public function test_diff_matches_ids_case_insensitively(): void
    {
        $before = self::trigger(self::FIRST);
        $after  = [...$before, 'id' => mb_strtoupper(self::FIRST)];

        $this->assertSame(['added' => [], 'changed' => [], 'removed' => []], FlowTriggerComparison::diff([$before], [$after]));
    }

    public function test_diff_keeps_the_order_of_the_after_list_for_added_and_of_the_before_list_for_removed(): void
    {
        $one   = self::trigger('00000000-0000-4000-8000-000000000001');
        $two   = self::trigger('00000000-0000-4000-8000-000000000002');
        $three = self::trigger('00000000-0000-4000-8000-000000000003');
        $four  = self::trigger('00000000-0000-4000-8000-000000000004');

        $diff = FlowTriggerComparison::diff([$two, $one], [$four, $three]);

        $this->assertSame([$four, $three], $diff['added']);
        $this->assertSame([$two, $one], $diff['removed']);
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return array{id: string, type: string, is_active: bool, priority: int, config: array<string, mixed>}
     */
    private static function trigger(string $id, string $type = 'message', array $config = []): array
    {
        return ['id' => $id, 'type' => $type, 'is_active' => true, 'priority' => 100, 'config' => $config];
    }
}
