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
use Tests\Support\InMemoryCallExecutionLedger;
use Tests\Support\InMemoryCredentialStore;
use Tests\Support\UsageGates;
use Tests\TestCase;

/**
 * `success_statuses` and `strict_mapping` of the `call` node, and how they meet the outbound ledger.
 */
final class CallNodeSuccessStatusesAndStrictMappingTest extends TestCase
{
    public function test_without_the_options_a_non_2xx_response_is_an_error_and_a_missing_path_is_null(): void
    {
        [$handler] = $this->handler([CallResult::error('http_4xx', ['x' => 1], ['status_code' => 404])]);

        $result = $handler->execute($this->node(), [], $this->context('k:1'));

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('http_4xx', $result->metadata['error_code']);
        $this->assertArrayHasKey('flow.created_id', $result->stateChanges);
        $this->assertNull($result->stateChanges['flow.created_id']);
    }

    public function test_a_listed_status_takes_the_success_handle_and_is_recorded_as_done(): void
    {
        [$handler, $transport] = $this->handler([CallResult::error('http_4xx', ['id' => 'u1'], ['status_code' => 409])]);
        $node                  = $this->node(['success_statuses' => [200, 409]]);

        $first  = $handler->execute($node, [], $this->context('k:1'));
        $second = $handler->execute($node, [], $this->context('k:1'));

        $this->assertSame('success', $first->sourceHandle);
        $this->assertArrayNotHasKey('error_code', $first->metadata);
        $this->assertSame('u1', $first->stateChanges['flow.created_id']);
        $this->assertCount(1, $transport->requests, 'An accepted status is a finished call: it is replayed, not sent again.');
        $this->assertSame('success', $second->sourceHandle);
        $this->assertTrue($second->metadata['deduplicated']);
    }

    public function test_an_unlisted_2xx_is_an_error_and_stays_retryable(): void
    {
        [$handler, $transport] = $this->handler([
            CallResult::ok(['id' => 'u1'], ['status_code' => 200]),
            CallResult::ok(['id' => 'u1'], ['status_code' => 201]),
        ]);
        $node = $this->node(['success_statuses' => [201]]);

        $first  = $handler->execute($node, [], $this->context('k:1'));
        $second = $handler->execute($node, [], $this->context('k:1'));

        $this->assertSame('error', $first->sourceHandle);
        $this->assertSame('http_other', $first->metadata['error_code']);
        $this->assertSame('success', $second->sourceHandle);
        $this->assertCount(2, $transport->requests, 'A rejected status is not recorded as done.');
        $this->assertSame([1, 2], $transport->attempts());
    }

    public function test_a_result_without_a_status_keeps_the_transport_verdict(): void
    {
        [$handler] = $this->handler([CallResult::ok(['id' => 'u1'])]);

        $result = $handler->execute($this->node(['success_statuses' => [201]]), [], $this->context('k:1'));

        $this->assertSame('success', $result->sourceHandle);
    }

    public function test_strict_mapping_sends_a_missing_path_to_error_and_writes_no_mapping(): void
    {
        [$handler] = $this->handler([CallResult::ok(['id' => 'u1'], ['status_code' => 200])]);
        $node      = $this->node(['strict_mapping' => true, 'result_mapping' => [
            ['from' => 'body.id', 'to' => ['name' => 'created_id', 'storage' => 'session']],
            ['from' => 'body.email', 'to' => ['name' => 'created_email', 'storage' => 'session']],
        ]]);

        $result = $handler->execute($node, [], $this->context('k:1'));

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('mapping_missing', $result->metadata['error_code']);
        $this->assertSame(['body.email'], $result->metadata['missing_paths']);
        $this->assertSame(200, $result->metadata['status_code']);
        $this->assertSame([], $result->stateChanges);
        $this->assertSame('mapping_missing: body.email', $result->errorMessage);
    }

    public function test_strict_mapping_accepts_a_path_whose_value_is_null_or_empty(): void
    {
        [$handler] = $this->handler([CallResult::ok(['id' => null, 'tags' => []], ['status_code' => 200])]);
        $node      = $this->node(['strict_mapping' => true, 'result_mapping' => [
            ['from' => 'body.id', 'to' => ['name' => 'created_id', 'storage' => 'session']],
            ['from' => 'body.tags', 'to' => ['name' => 'tags', 'storage' => 'session']],
        ]]);

        $result = $handler->execute($node, [], $this->context('k:1'));

        $this->assertSame('success', $result->sourceHandle);
        $this->assertNull($result->stateChanges['flow.created_id']);
        $this->assertSame([], $result->stateChanges['flow.tags']);
    }

    public function test_strict_mapping_leaves_an_error_response_lenient(): void
    {
        [$handler] = $this->handler([CallResult::error('http_5xx', 'down', ['status_code' => 503])]);

        $result = $handler->execute($this->node(['strict_mapping' => true]), [], $this->context('k:1'));

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('http_5xx', $result->metadata['error_code']);
        $this->assertArrayNotHasKey('missing_paths', $result->metadata);
        $this->assertNull($result->stateChanges['flow.created_id']);
    }

    public function test_a_strict_failure_is_replayed_the_same_way(): void
    {
        [$handler, $transport] = $this->handler([CallResult::ok(['x' => 1], ['status_code' => 200])]);
        $node                  = $this->node(['strict_mapping' => true]);

        $first  = $handler->execute($node, [], $this->context('k:1'));
        $second = $handler->execute($node, [], $this->context('k:1'));

        $this->assertSame('error', $first->sourceHandle);
        $this->assertSame('error', $second->sourceHandle);
        $this->assertSame('mapping_missing', $second->metadata['error_code']);
        $this->assertCount(1, $transport->requests);
    }

    public function test_publish_validation_names_a_bad_list_and_a_non_boolean_flag(): void
    {
        [$handler] = $this->handler([]);

        $good = $handler->validate($this->node(['success_statuses' => [200, '3xx', '400-404'], 'strict_mapping' => true]));
        $bad  = $handler->validate($this->node(['success_statuses' => ['ok'], 'strict_mapping' => 'yes']));

        $this->assertSame([], $good);
        $this->assertSame(
            ['call_invalid_success_statuses', 'call_invalid_strict_mapping'],
            array_map(static fn ($issue): string => $issue->code, $bad),
        );
        $this->assertSame('success_statuses', $bad[0]->field);
    }

    /**
     * @param  list<CallResult>  $results
     *
     * @return array{0: CallNodeHandler, 1: object}
     */
    private function handler(array $results): array
    {
        $transport = new class ($results) implements CallTransportInterface {
            /**
             * @var list<CallRequest>
             */
            public array $requests = [];

            /**
             * @param  list<CallResult>  $results
             */
            public function __construct(private array $results)
            {
            }

            public function id(): string
            {
                return 'recording';
            }

            public function execute(CallRequest $request, CallContext $context): CallResult
            {
                $this->requests[] = $request;

                return array_shift($this->results) ?? CallResult::ok();
            }

            /**
             * @return list<int>
             */
            public function attempts(): array
            {
                return array_map(static fn (CallRequest $r): int => (int) $r->options['attempt_number'], $this->requests);
            }
        };
        $registry = new CallTransportRegistry();
        $registry->register($transport);

        return [
            new CallNodeHandler($registry, new TemplateRenderer(), new VariableResolver(), UsageGates::quota(), new InMemoryCallExecutionLedger(), new InMemoryCredentialStore()),
            $transport,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     *
     * @return array<string, mixed>
     */
    private function node(array $extra = []): array
    {
        return [
            'id'     => 'call-1',
            'config' => [
                'transport'      => 'recording',
                'target'         => 'anything',
                'result_mapping' => [['from' => 'body.id', 'to' => ['name' => 'created_id', 'storage' => 'session']]],
                ...$extra,
            ],
        ];
    }

    private function context(string $engineKey): NodeExecutionContext
    {
        return new NodeExecutionContext(
            tenantId: UsageGates::TENANT_ID,
            contactId: 'contact-1',
            sessionId: 'session-1',
            nodeId: 'call-1',
            idempotencyKey: $engineKey,
            platform: 'telegram',
        );
    }
}
