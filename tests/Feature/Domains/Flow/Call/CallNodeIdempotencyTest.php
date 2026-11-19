<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Call;

use App\Domains\Flow\Call\CallAttempt;
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
use Tests\Support\InMemoryCallExecutionLedger;
use Tests\Support\InMemoryCredentialStore;
use Tests\Support\UsageGates;
use Tests\TestCase;

/**
 * A repeated execution of one `call` step is not sent again, and every send carries its attempt number
 * (`adr-20261118-call-outbound-idempotency-strategy`).
 */
final class CallNodeIdempotencyTest extends TestCase
{
    public function test_a_repeated_execution_after_a_success_is_replayed_without_calling_the_transport(): void
    {
        [$handler, $transport] = $this->handler([CallResult::ok(['id' => 'user-1'], ['status_code' => 201])]);

        $first  = $handler->execute($this->node(), [], $this->context('session-1:call-1:3'));
        $second = $handler->execute($this->node(), [], $this->context('session-1:call-1:3'));

        $this->assertCount(1, $transport->requests);
        $this->assertSame('success', $first->sourceHandle);
        $this->assertSame('success', $second->sourceHandle);
        $this->assertTrue($second->metadata['deduplicated']);
        $this->assertArrayNotHasKey('deduplicated', $first->metadata);
        $this->assertSame(201, $second->metadata['status_code']);
        $this->assertSame($first->stateChanges, $second->stateChanges);
        $this->assertSame('user-1', $second->stateChanges['flow.created_id'] ?? null);
    }

    public function test_the_ledger_is_looked_up_once_per_execution(): void
    {
        [$handler, , $ledger] = $this->handler([CallResult::ok()]);

        $handler->execute($this->node(), [], $this->context('session-1:call-1:3'));
        $this->assertSame(1, $ledger->lookups);

        $handler->execute($this->node(), [], $this->context('session-1:call-1:3'));
        $this->assertSame(2, $ledger->lookups);
    }

    public function test_a_failed_call_is_sent_again_with_the_same_key_and_the_next_attempt_number(): void
    {
        [$handler, $transport] = $this->handler([
            CallResult::error('transport_failure'),
            CallResult::ok(['id' => 'user-1']),
        ]);

        $first  = $handler->execute($this->node(), [], $this->context('session-1:call-1:3'));
        $second = $handler->execute($this->node(), [], $this->context('session-1:call-1:3'));

        $this->assertSame('error', $first->sourceHandle);
        $this->assertSame('success', $second->sourceHandle);
        $this->assertSame([1, 2], $transport->attempts());
        $this->assertCount(1, array_unique($transport->keys));
        $this->assertSame(2, $second->metadata['attempt_number']);
        $this->assertArrayNotHasKey('deduplicated', $second->metadata);
    }

    public function test_a_new_loop_pass_is_a_new_call(): void
    {
        [$handler, $transport] = $this->handler([CallResult::ok(), CallResult::ok()]);

        $handler->execute($this->node(), [], $this->context('session-1:call-1:3'));
        $handler->execute($this->node(), [], $this->context('session-1:call-1:5'));

        $this->assertCount(2, $transport->requests);
        $this->assertSame([1, 1], $transport->attempts());
    }

    public function test_the_attempt_number_cannot_be_set_by_the_flow_author(): void
    {
        [$handler, $transport] = $this->handler([CallResult::ok()]);

        $handler->execute(
            ['id' => 'call-1', 'config' => ['transport' => 'recording', 'target' => 'x', 'transport_options' => ['attempt_number' => 99]]],
            [],
            $this->context('session-1:call-1:3'),
        );

        $this->assertSame([1], $transport->attempts());
    }

    public function test_the_ledger_is_scoped_to_the_tenant(): void
    {
        [$handler, $transport] = $this->handler([CallResult::ok(), CallResult::ok()]);

        $handler->execute($this->node(), [], $this->context('session-1:call-1:3', tenantId: 'tenant-a'));
        $handler->execute($this->node(), [], $this->context('session-1:call-1:3', tenantId: 'tenant-b'));

        $this->assertCount(2, $transport->requests);
    }

    public function test_a_replay_is_not_refused_by_a_used_up_call_quota(): void
    {
        $transport = new ScriptedCallTransport('recording', []);
        $registry  = new CallTransportRegistry();
        $registry->register($transport);
        $ledger = new InMemoryCallExecutionLedger();
        $ledger->complete(UsageGates::TENANT_ID, 'session-1:call-1:3:call-1', CallResult::ok(['id' => 'user-1']));
        $handler = new CallNodeHandler($registry, new TemplateRenderer(), new VariableResolver(), UsageGates::quota(FakeUsageMeter::denying()), $ledger, new InMemoryCredentialStore());

        $modern = $handler->execute($this->node(), [], $this->context('session-1:call-1:3'));
        $legacy = $handler->execute(['id' => 'call-1', 'config' => ['url' => 'https://x.test/hook']], [], $this->context('session-1:call-1:3'));
        $fresh  = $handler->execute($this->node(), [], $this->context('session-1:call-1:4'));

        $this->assertSame('success', $modern->sourceHandle);
        $this->assertTrue($modern->metadata['deduplicated']);
        $this->assertSame('success', $legacy->sourceHandle);
        $this->assertSame([], $transport->requests);
        $this->assertSame('error', $fresh->sourceHandle, 'A call that is not a replay is still refused.');
        $this->assertSame('limit_reached', $fresh->metadata['error_type']);
    }

    public function test_a_legacy_node_is_deduplicated_too(): void
    {
        [$handler, $transport] = $this->handler([CallResult::ok(['ok' => true])], transportId: 'http');

        $legacy = ['id' => 'call-1', 'config' => ['url' => 'https://x.test/hook']];
        $first  = $handler->execute($legacy, [], $this->context('session-1:call-1:3'));
        $again  = $handler->execute($legacy, [], $this->context('session-1:call-1:3'));

        $this->assertCount(1, $transport->requests);
        $this->assertSame($first->sourceHandle, $again->sourceHandle);
        $this->assertTrue($again->metadata['deduplicated']);
    }

    public function test_a_ledger_that_never_remembers_does_not_block_the_call(): void
    {
        $transport = new ScriptedCallTransport('recording', [CallResult::ok(), CallResult::ok()]);
        $registry  = new CallTransportRegistry();
        $registry->register($transport);
        $ledger = new class () implements \App\Domains\Flow\Call\CallExecutionLedgerInterface {
            public function completed(string $tenantId, string $key): ?CallResult
            {
                return null;
            }

            public function startAttempt(string $tenantId, string $key): int
            {
                return 1;
            }

            public function complete(string $tenantId, string $key, CallResult $result): void
            {
            }
        };
        $handler = new CallNodeHandler($registry, new TemplateRenderer(), new VariableResolver(), UsageGates::quota(), $ledger, new InMemoryCredentialStore());

        $result = $handler->execute($this->node(), [], $this->context('session-1:call-1:3'));
        $handler->execute($this->node(), [], $this->context('session-1:call-1:3'));

        $this->assertSame('success', $result->sourceHandle);
        $this->assertCount(2, $transport->requests);
    }

    /**
     * @param  list<CallResult>  $results
     *
     * @return array{0: CallNodeHandler, 1: ScriptedCallTransport, 2: InMemoryCallExecutionLedger}
     */
    private function handler(array $results, string $transportId = 'recording'): array
    {
        $transport = new ScriptedCallTransport($transportId, $results);
        $registry  = new CallTransportRegistry();
        $registry->register($transport);

        $ledger = new InMemoryCallExecutionLedger();

        return [
            new CallNodeHandler($registry, new TemplateRenderer(), new VariableResolver(), UsageGates::quota(), $ledger, new InMemoryCredentialStore()),
            $transport,
            $ledger,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function node(): array
    {
        return [
            'id'     => 'call-1',
            'config' => [
                'transport'      => 'recording',
                'target'         => 'anything',
                'result_mapping' => [['from' => 'body.id', 'to' => ['name' => 'created_id', 'storage' => 'session']]],
            ],
        ];
    }

    private function context(string $engineKey, string $tenantId = UsageGates::TENANT_ID): NodeExecutionContext
    {
        return new NodeExecutionContext(
            tenantId: $tenantId,
            contactId: 'contact-1',
            sessionId: 'session-1',
            nodeId: 'call-1',
            idempotencyKey: $engineKey,
            platform: 'telegram',
        );
    }
}

final class ScriptedCallTransport implements CallTransportInterface
{
    /**
     * @var list<CallRequest>
     */
    public array $requests = [];

    /**
     * @var list<string>
     */
    public array $keys = [];

    /**
     * @param  list<CallResult>  $results
     */
    public function __construct(private readonly string $id, private array $results)
    {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function execute(CallRequest $request, CallContext $context): CallResult
    {
        $this->requests[] = $request;
        $this->keys[]     = $context->idempotencyKey;

        return array_shift($this->results) ?? CallResult::ok();
    }

    /**
     * @return list<mixed>
     */
    public function attempts(): array
    {
        return array_map(static fn (CallRequest $request): mixed => $request->options[CallAttempt::OPTION] ?? null, $this->requests);
    }
}
