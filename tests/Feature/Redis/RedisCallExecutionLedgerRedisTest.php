<?php

declare(strict_types=1);

namespace Tests\Feature\Redis;

use App\Domains\Flow\Call\CallExecutionLedgerInterface;
use App\Domains\Flow\Call\RedisCallExecutionLedger;
use DateTimeImmutable;
use Fapost\Foundation\Flow\Call\CallResult;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\FeatureTestCase;

/**
 * {@see RedisCallExecutionLedger} against a real Redis: the record, the attempt counter and their TTL.
 */
#[Group('redis')]
final class RedisCallExecutionLedgerRedisTest extends FeatureTestCase
{
    use InteractsWithRedisLocks;

    private string $tenant;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = 'tenant-' . Str::lower((string) Str::ulid());
        $this->key    = 'session-1:call-1:3';
    }

    protected function tearDown(): void
    {
        Redis::connection()->del($this->redisKey('done'), $this->redisKey('attempts'));

        parent::tearDown();
    }

    public function test_a_success_is_stored_and_read_back_with_its_ttl(): void
    {
        config(['flow.call.dedup_ttl_hours' => 200]);
        $ledger = $this->ledger();

        $this->assertNull($ledger->completed($this->tenant, $this->key));

        $ledger->complete($this->tenant, $this->key, CallResult::ok(['id' => 'u-1'], ['status_code' => 201]));

        $stored = $ledger->completed($this->tenant, $this->key);
        $this->assertNotNull($stored);
        $this->assertTrue($stored->success);
        $this->assertSame(['id' => 'u-1'], $stored->payload);
        $this->assertSame(201, $stored->metadata['status_code']);
        $this->assertGreaterThan(199 * 3600, Redis::connection()->ttl($this->redisKey('done')));
        $this->assertNull($ledger->completed('another-tenant', $this->key));
    }

    public function test_the_attempt_counter_grows_and_expires(): void
    {
        $ledger = $this->ledger();

        $this->assertSame(1, $ledger->startAttempt($this->tenant, $this->key));
        $this->assertSame(2, $ledger->startAttempt($this->tenant, $this->key));
        $this->assertGreaterThan(0, Redis::connection()->ttl($this->redisKey('attempts')));
    }

    public function test_a_result_over_the_size_cap_is_not_stored(): void
    {
        $ledger = $this->ledger();

        $ledger->complete($this->tenant, $this->key, CallResult::ok(str_repeat('x', RedisCallExecutionLedger::MAX_RECORD_BYTES + 1)));

        $this->assertNull($ledger->completed($this->tenant, $this->key));
    }

    public function test_a_payload_that_cannot_be_encoded_is_not_stored(): void
    {
        $ledger = $this->ledger();

        $ledger->complete($this->tenant, $this->key, CallResult::ok("\xB1\x31"));

        $this->assertNull($ledger->completed($this->tenant, $this->key));
    }

    public function test_a_result_with_an_object_payload_is_not_stored(): void
    {
        $ledger = $this->ledger();

        $ledger->complete($this->tenant, $this->key, CallResult::ok(new DateTimeImmutable('2026-01-01')));

        $this->assertNull($ledger->completed($this->tenant, $this->key));
    }

    public function test_a_zero_ttl_default_still_stores_the_record(): void
    {
        config(['flow.call.dedup_ttl_hours' => 0]);
        $ledger = $this->ledger();

        $ledger->complete($this->tenant, $this->key, CallResult::ok(['a' => 1]));

        $this->assertNotNull($ledger->completed($this->tenant, $this->key));
    }

    public function test_a_repeated_step_is_replayed_from_the_real_ledger_with_its_variables(): void
    {
        $transport = new class () implements \Fapost\Foundation\Flow\Call\CallTransportInterface {
            public int $sent = 0;

            public function id(): string
            {
                return 'counting';
            }

            public function execute(\Fapost\Foundation\Flow\Call\CallRequest $request, \Fapost\Foundation\Flow\Call\CallContext $context): CallResult
            {
                $this->sent++;

                return CallResult::ok(['data' => ['id' => 'u-1'], 'n' => 1.5], ['status_code' => 201]);
            }
        };
        $registry = new \App\Domains\Flow\Call\CallTransportRegistry();
        $registry->register($transport);
        $handler = new \App\Domains\Flow\Handlers\CallNodeHandler(
            $registry,
            new \App\Domains\Flow\Handlers\Support\TemplateRenderer(),
            new \App\Domains\Flow\State\Variables\VariableResolver(),
            \Tests\Support\UsageGates::quota(),
            $this->ledger(),
        );
        $node = ['id' => 'call-1', 'config' => [
            'transport'      => 'counting',
            'target'         => 'x',
            'result_mapping' => [['from' => 'body.data.id', 'to' => ['name' => 'created_id', 'storage' => 'session']]],
        ]];
        $context = new \Fapost\Foundation\DTO\NodeExecutionContext(
            tenantId: $this->tenant,
            contactId: 'c-1',
            sessionId: 's-1',
            nodeId: 'call-1',
            idempotencyKey: $this->key,
            platform: 'telegram',
        );

        $first  = $handler->execute($node, [], $context);
        $second = $handler->execute($node, [], $context);

        Redis::connection()->del("flow:call:{$this->tenant}:{$this->key}:call-1:done", "flow:call:{$this->tenant}:{$this->key}:call-1:attempts");
        $this->assertSame(1, $transport->sent);
        $this->assertSame($first->stateChanges, $second->stateChanges);
        $this->assertSame('u-1', $second->stateChanges['flow.created_id'] ?? null);
        $this->assertSame('success', $second->sourceHandle);
        $this->assertTrue($second->metadata['deduplicated']);
    }

    private function ledger(): CallExecutionLedgerInterface
    {
        return $this->app->make(CallExecutionLedgerInterface::class);
    }

    private function redisKey(string $part): string
    {
        return "flow:call:{$this->tenant}:{$this->key}:{$part}";
    }
}
