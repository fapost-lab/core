<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Webhook\Jobs\IncomingMessageJob;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\IncomingMessageType;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class IncomingMessageJobTest extends TestCase
{
    public function test_dispatch_carries_message_tenant_and_channel_and_uses_expected_retry_settings(): void
    {
        Bus::fake();

        $message = new IncomingMessage(
            updateId: 'up-1',
            externalUserId: 'user-1',
            externalChatId: 'chat-1',
            text: 'hello',
            type: IncomingMessageType::Text,
            platform: 'telegram',
            payload: ['username' => 'u1'],
        );

        IncomingMessageJob::dispatch(
            message: $message,
            tenantId: 'tenant-1',
            assistantId: 'assistant-1',
            channelId: 'channel-1',
            schema: 'main',
        );

        Bus::assertDispatched(IncomingMessageJob::class, static fn (IncomingMessageJob $job): bool => 3 === $job->tries
                && 5 === $job->backoff
                && 'tenant-1' === $job->tenantId
                && 'assistant-1' === $job->assistantId
                && 'channel-1' === $job->channelId
                && 'main' === $job->schema
                && 'up-1' === $job->message->updateId);
    }
}
