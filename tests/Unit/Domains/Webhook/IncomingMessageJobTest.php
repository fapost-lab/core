<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Webhook\Jobs\IncomingMessageJob;
use Fapost\Foundation\DTO\InboundWebhookPayload;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Smoke tests for the webhook ingress job. The full message routing
 * pipeline (commands, typing, lock, session state, execution) is tested
 * separately in {@see \Tests\Unit\Domains\Flow\Routing\MessageRouterTest}.
 */
final class IncomingMessageJobTest extends TestCase
{
    public function test_dispatch_carries_payload_and_uses_expected_retry_settings(): void
    {
        Bus::fake();

        IncomingMessageJob::dispatch($this->payload());

        Bus::assertDispatched(
            IncomingMessageJob::class,
            static fn (IncomingMessageJob $job): bool => 5 === $job->tries
                && 'tenant-1' === $job->payload->tenantId
                && 'assistant-1' === $job->payload->assistantId
                && 'channel-1' === $job->payload->channelId
                && 'main' === $job->payload->schema
                && 'tg:channel-1:up-1' === $job->payload->idempotencyKey
        );
    }

    private function payload(): InboundWebhookPayload
    {
        return new InboundWebhookPayload(
            tenantId: 'tenant-1',
            schema: 'main',
            assistantId: 'assistant-1',
            channelId: 'channel-1',
            platform: 'telegram',
            rawPayload: ['update_id' => 1],
            idempotencyKey: 'tg:channel-1:up-1',
            receivedAt: 1_700_000_000,
        );
    }
}
