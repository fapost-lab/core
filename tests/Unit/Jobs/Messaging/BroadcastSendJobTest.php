<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Messaging;

use App\Jobs\Messaging\BroadcastSendJob;
use FAPost\Foundation\Messaging\DeliveryResult;
use FAPost\Foundation\Messaging\MessagePayload;
use FAPost\Foundation\Messaging\MessageSenderInterface;
use FAPost\Foundation\Messaging\OutboundMessage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

final class BroadcastSendJobTest extends TestCase
{
    public function test_successful_delivery_completes_without_exception(): void
    {
        $sender = $this->mock(MessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once()->andReturn(new DeliveryResult(sent: true, providerMessageId: '77'));
        });

        $job = new BroadcastSendJob($this->message());

        $job->handle($sender);

        $this->addToAssertionCount(1);
    }

    public function test_sender_exception_bubbles_for_retry(): void
    {
        $sender = $this->mock(MessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once()->andThrow(new RuntimeException('provider unavailable'));
        });

        $job = new BroadcastSendJob($this->message());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provider unavailable');

        $job->handle($sender);
    }

    public function test_unsent_non_duplicate_result_throws_for_retry(): void
    {
        $sender = $this->mock(MessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once()->andReturn(new DeliveryResult(sent: false, error: 'provider failed'));
        });

        $job = new BroadcastSendJob($this->message());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provider failed');

        $job->handle($sender);
    }

    private function message(): OutboundMessage
    {
        return new OutboundMessage(
            idempotencyKey: 'idem-1',
            tenantId: 'tenant-1',
            channelId: 'channel-1',
            channelType: 'telegram',
            transportToken: 'bot-token',
            chatId: 'chat-1',
            payload: new MessagePayload(type: 'text', text: 'Hello'),
        );
    }
}
