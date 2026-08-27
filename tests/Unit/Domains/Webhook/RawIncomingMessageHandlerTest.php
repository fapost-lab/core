<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Webhook\Jobs\IncomingMessageJob;
use App\Domains\Webhook\Jobs\RawIncomingMessageHandler;
use FAPost\Foundation\DTO\InboundWebhookPayload;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Job;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use ValueError;

/**
 * Covers the seam between a queue job written by an external ingress runtime and
 * the job the PHP controller dispatches.
 *
 * Message handling itself is not retested here — that is the point of the design:
 * the bridge only decodes the wire payload and delegates, so both ingress paths
 * run identical code.
 */
final class RawIncomingMessageHandlerTest extends TestCase
{
    public function test_it_builds_the_incoming_job_from_the_wire_payload(): void
    {
        $captured = null;

        $container = $this->containerCapturing($captured);

        (new RawIncomingMessageHandler($container))->handle($this->queueJob(), $this->wirePayload());

        $this->assertInstanceOf(IncomingMessageJob::class, $captured[0]);
        $this->assertSame('handle', $captured[1]);

        $payload = $captured[0]->payload;

        $this->assertSame('tenant-1', $payload->tenantId);
        $this->assertSame('main', $payload->schema);
        $this->assertSame('assistant-9', $payload->assistantId);
        $this->assertSame('channel-777', $payload->channelId);
        $this->assertSame('telegram', $payload->platform);
        $this->assertSame('tg:channel-777:987654', $payload->idempotencyKey);
        $this->assertSame(1_700_000_000, $payload->receivedAt);
        $this->assertSame(987654, $payload->rawPayload['update_id']);
        $this->assertSame('req-abc', $payload->requestId);
    }

    /**
     * IncomingMessageJob releases itself back to the queue on engine lock
     * contention. Without the bound queue job that call has nothing to act on,
     * and contention would silently drop the message instead of backing off.
     */
    public function test_it_binds_the_queue_job_so_retries_keep_working(): void
    {
        $captured = null;
        $queueJob = $this->queueJob();

        (new RawIncomingMessageHandler($this->containerCapturing($captured)))
            ->handle($queueJob, $this->wirePayload());

        $this->assertSame($queueJob, $captured[0]->job);
    }

    /**
     * A payload from a newer gateway may carry fields this worker would ignore.
     * Failing loudly sends it to failed_jobs, where it can be inspected and
     * replayed, instead of processing a webhook with half its context.
     */
    public function test_it_rejects_an_unknown_payload_version(): void
    {
        $container = $this->mock(Container::class, function (MockInterface $mock): void {
            $mock->shouldReceive('call')->never();
        });

        $this->expectException(ValueError::class);

        (new RawIncomingMessageHandler($container))->handle(
            $this->queueJob(),
            ['v' => 99] + $this->wirePayload(),
        );
    }

    public function test_request_id_is_optional(): void
    {
        $captured = null;
        $payload  = $this->wirePayload();
        unset($payload['requestId']);

        (new RawIncomingMessageHandler($this->containerCapturing($captured)))
            ->handle($this->queueJob(), $payload);

        $this->assertNull($captured[0]->payload->requestId);
    }

    public function test_wire_format_round_trips(): void
    {
        $payload = InboundWebhookPayload::fromArray($this->wirePayload());

        $this->assertEquals($payload, InboundWebhookPayload::fromArray($payload->toArray()));
    }

    /**
     * The gateway writes this JSON directly, so the serialized shape is a
     * cross-language contract and its keys must not drift silently.
     */
    public function test_wire_format_keys_are_stable(): void
    {
        $this->assertSame(
            [
                'v',
                'tenantId',
                'schema',
                'assistantId',
                'channelId',
                'platform',
                'rawPayload',
                'idempotencyKey',
                'receivedAt',
                'requestId',
            ],
            array_keys(InboundWebhookPayload::fromArray($this->wirePayload())->toArray()),
        );
    }

    /**
     * @param  array{0: object, 1: string}|null  $captured
     */
    private function containerCapturing(mixed &$captured): Container
    {
        return $this->mock(Container::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('call')
                ->once()
                ->with(Mockery::capture($captured));
        });
    }

    private function queueJob(): Job
    {
        return $this->mock(Job::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function wirePayload(): array
    {
        return [
            'v'              => InboundWebhookPayload::VERSION,
            'tenantId'       => 'tenant-1',
            'schema'         => 'main',
            'assistantId'    => 'assistant-9',
            'channelId'      => 'channel-777',
            'platform'       => 'telegram',
            'rawPayload'     => ['update_id' => 987654, 'message' => ['text' => 'hi']],
            'idempotencyKey' => 'tg:channel-777:987654',
            'receivedAt'     => 1_700_000_000,
            'requestId'      => 'req-abc',
        ];
    }
}
