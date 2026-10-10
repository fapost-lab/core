<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Call;

use App\Domains\Flow\Call\CallTransportRegistry;
use App\Domains\Flow\Handlers\CallNodeHandler;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\State\Variables\VariableResolver;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\Flow\Call\CallContext;
use Fapost\Foundation\Flow\Call\CallRequest;
use Fapost\Foundation\Flow\Call\CallResult;
use Fapost\Foundation\Flow\Call\CallTransportInterface;
use Tests\Support\FakeUsageMeter;
use Tests\Support\UsageGates;
use Tests\TestCase;

/**
 * The key a transport deduplicates by, and the unit the call_executions limit counts, identify one execution
 * of the node: a loop pass is a new execution, a queue retry of the same step is the same one.
 */
final class CallNodeExecutionKeyTest extends TestCase
{
    public function test_each_loop_pass_gets_its_own_transport_key_and_counted_unit(): void
    {
        [$handler, $transport, $meter] = $this->handler();

        // The engine's key changes with every persisted step (the session version), the node stays the same.
        $handler->execute($this->node(), [], $this->context('session-1:call-1:3'));
        $handler->execute($this->node(), [], $this->context('session-1:call-1:5'));

        $this->assertCount(2, array_unique($transport->keys));
        $this->assertCount(2, array_unique(array_map(static fn ($unit): string => $unit->unitKey, $meter->units)));
    }

    public function test_a_retried_step_keeps_the_transport_key_and_the_counted_unit(): void
    {
        [$handler, $transport, $meter] = $this->handler();

        $handler->execute($this->node(), [], $this->context('session-1:call-1:3'));
        $handler->execute($this->node(), [], $this->context('session-1:call-1:3'));

        $this->assertCount(1, array_unique($transport->keys));
        $this->assertCount(1, array_unique(array_map(static fn ($unit): string => $unit->unitKey, $meter->units)));
    }

    public function test_the_first_node_of_an_inbound_message_and_a_later_node_get_different_keys(): void
    {
        [$handler, $transport] = $this->handler();

        $handler->execute($this->node('call-1'), [], $this->context('update-9|session-1', 'call-1'));
        $handler->execute($this->node('call-2'), [], $this->context('session-1:call-2:4', 'call-2'));

        $this->assertCount(2, array_unique($transport->keys));
    }

    /**
     * @return array{0: CallNodeHandler, 1: RecordingCallTransport, 2: FakeUsageMeter}
     */
    private function handler(): array
    {
        $transport = new RecordingCallTransport();
        $registry  = new CallTransportRegistry();
        $registry->register($transport);
        $meter = FakeUsageMeter::allowing();

        return [
            new CallNodeHandler($registry, new TemplateRenderer(), new VariableResolver(), UsageGates::quota($meter)),
            $transport,
            $meter,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function node(string $id = 'call-1'): array
    {
        return ['id' => $id, 'config' => ['transport' => 'recording', 'target' => 'anything']];
    }

    private function context(string $engineKey, string $nodeId = 'call-1'): NodeExecutionContext
    {
        return new NodeExecutionContext(
            tenantId: UsageGates::TENANT_ID,
            contactId: 'contact-1',
            sessionId: 'session-1',
            nodeId: $nodeId,
            idempotencyKey: $engineKey,
            platform: 'telegram',
        );
    }
}

final class RecordingCallTransport implements CallTransportInterface
{
    /**
     * @var list<string>
     */
    public array $keys = [];

    public function id(): string
    {
        return 'recording';
    }

    public function execute(CallRequest $request, CallContext $context): CallResult
    {
        $this->keys[] = $context->idempotencyKey;

        return CallResult::ok([]);
    }
}
