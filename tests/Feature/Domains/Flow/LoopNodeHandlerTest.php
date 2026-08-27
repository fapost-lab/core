<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\Handlers\LoopEndNodeHandler;
use App\Domains\Flow\Handlers\LoopNodeHandler;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unit tests for LoopNodeHandler and LoopEndNodeHandler in isolation.
 * Full engine execution (counted + while loops) is covered by LoopEngineTest.
 */
final class LoopNodeHandlerTest extends TestCase
{
    // -----------------------------------------------------------------------
    // LoopNodeHandler — counted mode
    // -----------------------------------------------------------------------

    public function test_counted_loop_initializes_iterator_and_enters_body(): void
    {
        $result = $this->loopHandler()->execute(
            $this->loopNode('counted', count: 3),
            [],
            $this->context(),
        );

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('loop', $result->sourceHandle);
        $this->assertSame(1, $result->stateChanges['flow.iterator']);
        $this->assertSame(3, $result->stateChanges['flow.iterator_total']);
    }

    public function test_counted_loop_continues_body_when_iterator_within_range(): void
    {
        $result = $this->loopHandler()->execute(
            $this->loopNode('counted', count: 3),
            ['flow' => ['iterator' => 2]],
            $this->context(),
        );

        $this->assertSame('loop', $result->sourceHandle);
        // Iterator already set — no re-init in stateChanges.
        $this->assertArrayNotHasKey('flow.iterator', $result->stateChanges);
    }

    public function test_counted_loop_exits_and_nullifies_state_when_iterator_exceeds_total(): void
    {
        $result = $this->loopHandler()->execute(
            $this->loopNode('counted', count: 3),
            ['flow' => ['iterator' => 4, 'iterator_total' => 3]],
            $this->context(),
        );

        $this->assertSame('default', $result->sourceHandle);
        $this->assertNull($result->stateChanges['flow.iterator']);
        $this->assertNull($result->stateChanges['flow.iterator_total']);
    }

    public function test_counted_loop_writes_total_every_pass(): void
    {
        $result = $this->loopHandler()->execute(
            $this->loopNode('counted', count: 5),
            ['flow' => ['iterator' => 3]],
            $this->context(),
        );

        $this->assertSame('loop', $result->sourceHandle);
        $this->assertSame(5, $result->stateChanges['flow.iterator_total']);
    }

    public function test_counted_total_key_derives_from_custom_iterator_name(): void
    {
        $result = $this->loopHandler()->execute(
            $this->loopNode('counted', count: 2, iteratorName: 'step'),
            [],
            $this->context(),
        );

        $this->assertSame(2, $result->stateChanges['flow.step_total']);
        $this->assertArrayNotHasKey('flow.iterator_total', $result->stateChanges);
    }

    // -----------------------------------------------------------------------
    // LoopNodeHandler — while mode
    // -----------------------------------------------------------------------

    public function test_while_loop_enters_body_when_condition_is_true(): void
    {
        $result = $this->loopHandler()->execute(
            $this->whileLoopNode(operator: 'eq', value: 'yes'),
            ['flow' => ['flag' => 'yes']],
            $this->context(),
        );

        $this->assertSame('loop', $result->sourceHandle);
        $this->assertSame(1, $result->stateChanges['flow.iterator']);
    }

    public function test_while_loop_exits_and_nullifies_iterator_when_condition_is_false(): void
    {
        $result = $this->loopHandler()->execute(
            $this->whileLoopNode(operator: 'eq', value: 'yes'),
            ['flow' => ['flag' => 'no', 'iterator' => 3]],
            $this->context(),
        );

        $this->assertSame('default', $result->sourceHandle);
        $this->assertNull($result->stateChanges['flow.iterator']);
    }

    // -----------------------------------------------------------------------
    // LoopNodeHandler — custom iterator_name
    // -----------------------------------------------------------------------

    public function test_custom_iterator_name_is_respected(): void
    {
        $result = $this->loopHandler()->execute(
            $this->loopNode('counted', count: 2, iteratorName: 'step'),
            [],
            $this->context(),
        );

        $this->assertSame('loop', $result->sourceHandle);
        $this->assertSame(1, $result->stateChanges['flow.step']);
        $this->assertArrayNotHasKey('flow.iterator', $result->stateChanges);
    }

    // -----------------------------------------------------------------------
    // LoopEndNodeHandler
    // -----------------------------------------------------------------------

    public function test_loop_end_increments_iterator_and_returns_null_handle(): void
    {
        $handler = new LoopEndNodeHandler();

        $result = $handler->execute(
            $this->loopEndNode(loopNodeId: 'loop-1', iteratorName: 'iterator'),
            ['flow' => ['iterator' => 2]],
            $this->context(),
        );

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertNull($result->sourceHandle);
        $this->assertSame(3, $result->stateChanges['flow.iterator']);
    }

    public function test_loop_end_increments_custom_iterator_name(): void
    {
        $handler = new LoopEndNodeHandler();

        $result = $handler->execute(
            $this->loopEndNode(loopNodeId: 'loop-1', iteratorName: 'step'),
            ['flow' => ['step' => 1]],
            $this->context(),
        );

        $this->assertSame(2, $result->stateChanges['flow.step']);
    }

    public function test_loop_end_uses_default_iterator_name_when_config_absent(): void
    {
        $handler = new LoopEndNodeHandler();

        $result = $handler->execute(
            ['id' => 'le-1', 'type' => 'loop_end', 'version' => 1, 'config' => []],
            ['flow' => ['iterator' => 5]],
            $this->context(),
        );

        $this->assertSame(6, $result->stateChanges['flow.iterator']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function loopHandler(): LoopNodeHandler
    {
        return new LoopNodeHandler(
            $this->app->make(DataAccessorRegistryInterface::class),
            $this->app->make(VariableResolverInterface::class),
        );
    }

    /** @return array<string, mixed> */
    private function loopNode(
        string $mode,
        int $count = 3,
        string $iteratorName = 'iterator',
    ): array {
        return [
            'id'      => 'loop-1',
            'type'    => LoopNodeHandler::TYPE,
            'version' => 1,
            'config'  => [
                'mode'          => $mode,
                'iterator_name' => $iteratorName,
                'count_source'  => ['type' => 'literal', 'value' => $count],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function whileLoopNode(string $operator, string $value): array
    {
        return [
            'id'      => 'loop-1',
            'type'    => LoopNodeHandler::TYPE,
            'version' => 1,
            'config'  => [
                'mode'          => 'while',
                'iterator_name' => 'iterator',
                'condition'     => [
                    'left'     => 'flow.flag',
                    'operator' => $operator,
                    'value'    => $value,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function loopEndNode(string $loopNodeId, string $iteratorName = 'iterator'): array
    {
        return [
            'id'      => 'le-1',
            'type'    => LoopEndNodeHandler::TYPE,
            'version' => 1,
            'config'  => [
                'loop_node_id'  => $loopNodeId,
                'iterator_name' => $iteratorName,
            ],
        ];
    }

    private function context(): NodeExecutionContext
    {
        return new NodeExecutionContext(
            tenantId: (string) Str::uuid(),
            contactId: (string) Str::uuid(),
            sessionId: (string) Str::uuid(),
            nodeId: 'loop-1',
            idempotencyKey: Str::random(16),
            platform: 'telegram',
        );
    }
}
