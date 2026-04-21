<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Messaging;

use App\Jobs\Messaging\SendTransactionalMessageJob;
use FAPost\Foundation\Messaging\DeliveryResult;
use FAPost\Foundation\Messaging\MessagePayload;
use FAPost\Foundation\Messaging\MessageSenderInterface;
use FAPost\Foundation\Messaging\OutboundMessage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

final class SendTransactionalMessageJobTest extends TestCase
{
    public function test_duplicate_result_returns_without_exception(): void
    {
        $sender = $this->mock(MessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once()->andReturn(new DeliveryResult(sent: false, duplicate: true));
        });

        $job = new SendTransactionalMessageJob($this->message());
        $job->handle($sender);

        $this->assertTrue(true);
    }

    public function test_unsent_non_duplicate_result_throws_to_trigger_retry(): void
    {
        $sender = $this->mock(MessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once()->andReturn(new DeliveryResult(sent: false, error: 'provider failed'));
        });

        $job = new SendTransactionalMessageJob($this->message());

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
