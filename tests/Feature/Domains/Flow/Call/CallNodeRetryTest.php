<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Call;

use App\Domains\Flow\Call\CallCredentialResolver;
use App\Domains\Flow\Call\CallTransportRegistry;
use App\Domains\Flow\Handlers\CallNodeHandler;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\State\Variables\VariableResolver;
use Closure;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\Flow\Call\CallContext;
use Fapost\Foundation\Flow\Call\CallRequest;
use Fapost\Foundation\Flow\Call\CallResult;
use Fapost\Foundation\Flow\Call\CallTransportInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Tests\Support\FakeUsageMeter;
use Tests\Support\InMemoryCallExecutionLedger;
use Tests\Support\InMemoryCredentialStore;
use Tests\Support\UsageGates;
use Tests\TestCase;

/**
 * The `retry` option of the `call` node: which failures are sent again, the waits, and the node's time window.
 */
final class CallNodeRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-19 12:00:00');
        Sleep::fake(syncWithCarbon: true);
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_without_retry_a_503_is_sent_once(): void
    {
        [$handler, $transport] = $this->handler([$this->failure(503), $this->success()]);

        $result = $handler->execute($this->node(), [], $this->context('k:1'));

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('http_5xx', $result->metadata['error_code']);
        $this->assertCount(1, $transport->requests);
        $this->assertArrayNotHasKey('attempts', $result->metadata);
        Sleep::assertNeverSlept();
    }

    public function test_a_retried_call_keeps_its_key_numbers_its_sends_and_backs_off_exponentially(): void
    {
        [$handler, $transport] = $this->handler([$this->failure(503), $this->failure(503), $this->success()]);

        $result = $handler->execute($this->node(['retry' => ['max_attempts' => 3]]), [], $this->context('k:1'));

        $this->assertSame('success', $result->sourceHandle);
        $this->assertCount(3, $transport->requests);
        $this->assertSame([1, 2, 3], $transport->attempts());
        $this->assertSame(['k:1:call-1'], array_values(array_unique($transport->keys)));
        $this->assertSame(3, $result->metadata['attempts']);
        $this->assertSame(3, $result->metadata['attempt_number']);
        Sleep::assertSequence([Sleep::for(500)->milliseconds(), Sleep::for(1000)->milliseconds()]);
    }

    public function test_a_fixed_backoff_waits_the_same_delay_every_time(): void
    {
        [$handler] = $this->handler([$this->failure(503), $this->failure(503), $this->success()]);

        $handler->execute($this->node(['retry' => ['max_attempts' => 3, 'backoff' => 'fixed', 'delay_ms' => 200]]), [], $this->context('k:1'));

        Sleep::assertSequence([Sleep::for(200)->milliseconds(), Sleep::for(200)->milliseconds()]);
    }

    public function test_a_404_is_sent_once(): void
    {
        [$handler, $transport] = $this->handler([$this->failure(404), $this->success()]);

        $result = $handler->execute($this->node(['retry' => ['max_attempts' => 3]]), [], $this->context('k:1'));

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('http_4xx', $result->metadata['error_code']);
        $this->assertSame(404, $result->metadata['status_code']);
        $this->assertCount(1, $transport->requests);
        $this->assertArrayNotHasKey('attempts', $result->metadata);
        Sleep::assertNeverSlept();
    }

    public function test_a_refused_egress_without_a_status_is_sent_once(): void
    {
        [$handler, $transport] = $this->handler([CallResult::error('egress_denied', 'blocked'), $this->success()]);

        $result = $handler->execute($this->node(['retry' => ['max_attempts' => 3]]), [], $this->context('k:1'));

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('egress_denied', $result->metadata['error_code']);
        $this->assertArrayNotHasKey('status_code', $result->metadata);
        $this->assertCount(1, $transport->requests);
        Sleep::assertNeverSlept();
    }

    public function test_a_429_is_retried_by_default(): void
    {
        [$handler, $transport] = $this->handler([$this->failure(429), $this->success()]);

        $result = $handler->execute($this->node(['retry' => ['max_attempts' => 2]]), [], $this->context('k:1'));

        $this->assertSame('success', $result->sourceHandle);
        $this->assertCount(2, $transport->requests);
    }

    public function test_the_on_list_can_leave_a_429_unretried(): void
    {
        [$handler, $transport] = $this->handler([$this->failure(429), $this->success()]);

        $result = $handler->execute($this->node(['retry' => ['max_attempts' => 3, 'on' => ['5xx']]]), [], $this->context('k:1'));

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('http_4xx', $result->metadata['error_code']);
        $this->assertSame(429, $result->metadata['status_code']);
        $this->assertCount(1, $transport->requests);
    }

    public function test_a_transport_failure_without_a_status_is_retried_by_default(): void
    {
        [$handler, $transport] = $this->handler([CallResult::error('transport_failure', 'timeout'), $this->success()]);

        $result = $handler->execute($this->node(['retry' => ['max_attempts' => 2]]), [], $this->context('k:1'));

        $this->assertSame('success', $result->sourceHandle);
        $this->assertCount(2, $transport->requests);
    }

    public function test_a_status_the_node_accepts_is_a_success_and_is_never_retried(): void
    {
        [$handler, $transport] = $this->handler([$this->failure(503), $this->success()]);

        $result = $handler->execute(
            $this->node(['success_statuses' => [503], 'retry' => ['max_attempts' => 3]]),
            [],
            $this->context('k:1'),
        );

        $this->assertSame('success', $result->sourceHandle);
        $this->assertCount(1, $transport->requests);
        $this->assertArrayNotHasKey('attempts', $result->metadata);
        Sleep::assertNeverSlept();
    }

    public function test_a_short_retry_after_header_lengthens_the_wait(): void
    {
        [$handler, $transport] = $this->handler([
            CallResult::error('http_4xx', 'slow down', ['status_code' => 429, 'headers' => ['Retry-After' => '2']]),
            $this->success(),
        ]);

        $result = $handler->execute($this->node(['retry' => ['max_attempts' => 2]]), [], $this->context('k:1'));

        $this->assertSame('success', $result->sourceHandle);
        $this->assertCount(2, $transport->requests);
        Sleep::assertSequence([Sleep::for(2000)->milliseconds()]);
    }

    public function test_a_retry_after_beyond_the_window_stops_retrying_at_once(): void
    {
        [$handler, $transport] = $this->handler([
            CallResult::error('http_4xx', 'slow down', ['status_code' => 429, 'headers' => ['retry-after' => '60']]),
            $this->success(),
        ]);

        $result = $handler->execute($this->node(['retry' => ['max_attempts' => 3]]), [], $this->context('k:1'));

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('window', $result->metadata['retry_stopped']);
        $this->assertCount(1, $transport->requests);
        Sleep::assertNeverSlept();
    }

    public function test_a_slow_first_send_leaves_no_room_for_a_retry(): void
    {
        [$handler, $transport] = $this->handler([
            $this->slow(10, CallResult::error('transport_failure', 'timeout')),
            $this->success(),
        ]);

        $result = $handler->execute($this->node(['retry' => ['max_attempts' => 3]]), [], $this->context('k:1'));

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('transport_failure', $result->metadata['error_code']);
        $this->assertSame('window', $result->metadata['retry_stopped']);
        $this->assertCount(1, $transport->requests);
        Sleep::assertNeverSlept();
    }

    public function test_a_retry_gets_a_timeout_cut_to_what_is_left_of_the_window(): void
    {
        [$handler, $transport] = $this->handler([
            $this->slow(2, CallResult::error('transport_failure', 'timeout')),
            $this->success(),
        ]);

        $result = $handler->execute(
            $this->node(['transport_options' => ['timeout' => 10], 'retry' => ['max_attempts' => 2]]),
            [],
            $this->context('k:1'),
        );

        $this->assertSame('success', $result->sourceHandle);
        $this->assertCount(2, $transport->requests);
        $this->assertSame(10, $transport->requests[0]->options['timeout']);
        $this->assertSame(7, $transport->requests[1]->options['timeout']);
        $this->assertArrayNotHasKey('retry_stopped', $result->metadata);
    }

    public function test_exhausted_attempts_take_error_and_nothing_is_recorded_as_done(): void
    {
        [$handler, $transport] = $this->handler([
            $this->failure(503),
            $this->failure(502),
            $this->failure(500),
            $this->success(),
        ]);
        $node = $this->node(['retry' => ['max_attempts' => 3]]);

        $first  = $handler->execute($node, [], $this->context('k:1'));
        $second = $handler->execute($node, [], $this->context('k:1'));

        $this->assertSame('error', $first->sourceHandle);
        $this->assertSame('http_5xx', $first->metadata['error_code']);
        $this->assertSame(500, $first->metadata['status_code']);
        $this->assertSame(3, $first->metadata['attempts']);
        $this->assertArrayNotHasKey('retry_stopped', $first->metadata);
        $this->assertCount(4, $transport->requests, 'A failure is not recorded, so the next execution sends again.');
        $this->assertSame('success', $second->sourceHandle);
        $this->assertArrayNotHasKey('deduplicated', $second->metadata);
    }

    public function test_a_retried_execution_spends_one_call_execution(): void
    {
        $meter                 = FakeUsageMeter::allowing();
        [$handler, $transport] = $this->handler([$this->failure(503), $this->failure(503), $this->success()], $meter);

        $result = $handler->execute($this->node(['retry' => ['max_attempts' => 3]]), [], $this->context('k:1'));

        $this->assertSame('success', $result->sourceHandle);
        $this->assertCount(3, $transport->requests);
        $this->assertCount(1, $meter->units, 'One execution is one unit, however many sends it makes.');
    }

    public function test_publish_validation_accepts_a_good_retry_and_names_each_bad_one(): void
    {
        [$handler] = $this->handler([]);

        $this->assertSame([], $handler->validate($this->node(['retry' => [
            'max_attempts' => 5,
            'delay_ms'     => 250,
            'backoff'      => 'fixed',
            'on'           => ['network', '5xx', '429'],
        ]])));

        $bad = [
            ['max_attempts' => 9],
            ['delay_ms' => 50],
            ['backoff' => 'linear'],
            ['on' => ['4xx']],
            ['on' => [429]],
            ['foo' => 1],
            [1, 2],
        ];

        foreach ($bad as $retry) {
            $issues = $handler->validate($this->node(['retry' => $retry]));

            $this->assertCount(1, $issues, json_encode($retry, JSON_THROW_ON_ERROR));
            $this->assertSame('call_invalid_retry', $issues[0]->code);
            $this->assertSame('retry', $issues[0]->field);
        }
    }

    private function failure(int $status): CallResult
    {
        return CallResult::error(
            $status >= 500 ? 'http_5xx' : 'http_4xx',
            ['error' => 'x'],
            ['status_code' => $status],
        );
    }

    private function success(): CallResult
    {
        return CallResult::ok(['id' => 'u1'], ['status_code' => 200]);
    }

    /**
     * A send that takes `$seconds` of (test) time before it answers.
     */
    private function slow(int $seconds, CallResult $result): Closure
    {
        return static function () use ($seconds, $result): CallResult {
            Carbon::setTestNow(Carbon::now()->addSeconds($seconds));

            return $result;
        };
    }

    /**
     * @param  list<CallResult|Closure(): CallResult>  $results
     *
     * @return array{0: CallNodeHandler, 1: object}
     */
    private function handler(array $results, ?FakeUsageMeter $meter = null): array
    {
        $transport = new class ($results) implements CallTransportInterface {
            /**
             * @var list<CallRequest>
             */
            public array $requests = [];

            /**
             * @var list<string>
             */
            public array $keys = [];

            /**
             * @param  list<CallResult|Closure(): CallResult>  $results
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
                $this->keys[]     = $context->idempotencyKey;

                $next = array_shift($this->results) ?? CallResult::ok();

                return $next instanceof Closure ? $next() : $next;
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
            new CallNodeHandler($registry, new TemplateRenderer(), new VariableResolver(), UsageGates::quota($meter), new InMemoryCallExecutionLedger(), new CallCredentialResolver(new InMemoryCredentialStore())),
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
