<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Trace;

use App\Domains\Flow\Trace\LoopIterations;
use PHPUnit\Framework\TestCase;

final class LoopIterationsTest extends TestCase
{
    public function test_a_flow_without_loops_has_no_iterations(): void
    {
        $nodes = [['id' => 'a', 'type' => 'assign', 'config' => []]];

        $this->assertSame([], LoopIterations::of($nodes, ['flow' => ['iterator' => 3]]));
    }

    public function test_nested_loops_report_every_loop_whose_iterator_is_set(): void
    {
        $nodes = [
            $this->loop('outer', 'o'),
            $this->loop('inner', 'n'),
            ['id' => 'body', 'type' => 'assign', 'config' => []],
        ];

        $this->assertSame(
            ['outer' => 2, 'inner' => 1],
            LoopIterations::of($nodes, ['flow' => ['o' => 2, 'n' => 1]]),
        );
    }

    public function test_a_loop_whose_iterator_is_not_set_is_absent(): void
    {
        $nodes = [$this->loop('outer', 'o'), $this->loop('inner', 'n')];

        $this->assertSame(['outer' => 2], LoopIterations::of($nodes, ['flow' => ['o' => 2]]));
        $this->assertSame([], LoopIterations::of($nodes, ['flow' => ['o' => null]]));
        $this->assertSame([], LoopIterations::of($nodes, []));
    }

    public function test_the_default_iterator_name_is_used_when_the_config_has_none(): void
    {
        $nodes = [
            ['id' => 'l1', 'type' => 'loop', 'config' => []],
            ['id' => 'l2', 'type' => 'loop', 'config' => ['iterator_name' => '']],
            ['id' => 'l3', 'type' => 'loop'],
        ];

        $this->assertSame(
            ['l1' => 4, 'l2' => 4, 'l3' => 4],
            LoopIterations::of($nodes, ['flow' => ['iterator' => 4]]),
        );
    }

    public function test_a_pass_of_zero_counts_as_set(): void
    {
        $this->assertSame(['l' => 0], LoopIterations::of([$this->loop('l', 'i')], ['flow' => ['i' => 0]]));
    }

    public function test_numeric_passes_are_cast_and_other_values_are_ignored(): void
    {
        $nodes = [$this->loop('a', 'a'), $this->loop('b', 'b'), $this->loop('c', 'c'), $this->loop('d', 'd')];

        $this->assertSame(
            ['a' => 3, 'b' => 5],
            LoopIterations::of($nodes, ['flow' => ['a' => '3', 'b' => 5.0, 'c' => 'x', 'd' => 1.5]]),
        );
    }

    public function test_malformed_nodes_are_skipped(): void
    {
        $nodes = [
            'not a node',
            ['type' => 'loop', 'config' => ['iterator_name' => 'i']],
            ['id' => 7, 'type' => 'loop', 'config' => ['iterator_name' => 'i']],
            ['id' => 'ok', 'type' => 'loop', 'config' => 'broken'],
        ];

        $this->assertSame(['ok' => 1], LoopIterations::of($nodes, ['flow' => ['iterator' => 1, 'i' => 9]]));
    }

    /**
     * @return array<string, mixed>
     */
    private function loop(string $id, string $iterator): array
    {
        return ['id' => $id, 'type' => 'loop', 'config' => ['iterator_name' => $iterator]];
    }
}
