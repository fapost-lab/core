<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Messaging;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Conversation\Capture\ConversationCaptureFactory;
use App\Domains\Conversation\Contracts\ConversationLoggerInterface;
use App\Domains\Conversation\DTO\MessageLogEntry;
use App\Domains\Conversation\Enums\DeliveryStatus;
use App\Domains\Messaging\Exceptions\RateLimitExceededException;
use App\Domains\Messaging\Exceptions\UnsupportedChannelException;
use App\Domains\Messaging\MessageSender;
use Closure;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessagePayload;
use Fapost\Foundation\Messaging\OutboundMessage;
use Fapost\Foundation\Messaging\ProviderSenderInterface;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class MessageSenderTest extends TestCase
{
    public function test_idempotency_hit_returns_duplicate_and_provider_is_not_called(): void
    {
        $connection = $this->mock(Connection::class, function (MockInterface $mock): void {
            $mock->shouldReceive('set')->once()->with('msg:sent:idem-1', 'processing', 'EX', 86400, 'NX')->andReturn(false);
            $mock->shouldReceive('pipeline')->never();
        });
        $redis = $this->redisFactory($connection);

        $provider = new InMemoryProviderSender();
        $sender   = new MessageSender($this->registry($provider), $redis, 30, $this->nullConversationLogger(), new ConversationCaptureFactory());

        $result = $sender->send($this->message(idempotencyKey: 'idem-1'));

        $this->assertFalse($result->sent);
        $this->assertTrue($result->duplicate);
        $this->assertSame(0, $provider->calls);
    }

    public function test_rate_limit_exceeded_throws_exception(): void
    {
        $connection = $this->mock(Connection::class, function (MockInterface $mock): void {
            $mock->shouldReceive('set')->once()->with('msg:sent:idem-2', 'processing', 'EX', 86400, 'NX')->andReturn(true);
            $mock->shouldReceive('pipeline')->once()->andReturn([31, true]);
            $mock->shouldReceive('del')->once()->with('msg:sent:idem-2');
        });
        $redis = $this->redisFactory($connection);

        $provider = new InMemoryProviderSender();
        $sender   = new MessageSender($this->registry($provider), $redis, 30, $this->nullConversationLogger(), new ConversationCaptureFactory());

        $this->expectException(RateLimitExceededException::class);

        $sender->send($this->message(idempotencyKey: 'idem-2'));
    }

    public function test_provider_not_found_throws_exception(): void
    {
        $connection = $this->mock(Connection::class, function (MockInterface $mock): void {
            $mock->shouldReceive('set')->once()->with('msg:sent:idem-3', 'processing', 'EX', 86400, 'NX')->andReturn(true);
            $mock->shouldReceive('pipeline')->once()->andReturn([1, true]);
            $mock->shouldReceive('del')->once()->with('msg:sent:idem-3');
        });
        $redis = $this->redisFactory($connection);

        $sender = new MessageSender($this->registry(null), $redis, 30, $this->nullConversationLogger(), new ConversationCaptureFactory());

        $this->expectException(UnsupportedChannelException::class);

        $sender->send($this->message(idempotencyKey: 'idem-3'));
    }

    public function test_successful_delivery_calls_provider_once_and_marks_idempotency_key(): void
    {
        $connection = $this->mock(Connection::class, function (MockInterface $mock): void {
            $mock->shouldReceive('set')->once()->with('msg:sent:idem-4', 'processing', 'EX', 86400, 'NX')->andReturn(true);
            $mock->shouldReceive('pipeline')->once()->andReturn([1, true]);
            $mock->shouldReceive('set')->once()->with('msg:sent:idem-4', '1', 'EX', 86400);
        });
        $redis = $this->redisFactory($connection);

        $provider = new InMemoryProviderSender();
        $sender   = new MessageSender($this->registry($provider), $redis, 30, $this->nullConversationLogger(), new ConversationCaptureFactory());

        $result = $sender->send($this->message(idempotencyKey: 'idem-4'));

        $this->assertTrue($result->sent);
        $this->assertSame('provider-message-1', $result->providerMessageId);
        $this->assertSame(1, $provider->calls);
    }

    public function test_pipeline_sends_incr_and_expire_for_rate_key(): void
    {
        $key = 'rate:channel-1:chat-1';

        $connection = $this->mock(Connection::class, function (MockInterface $mock) use ($key): void {
            $mock->shouldReceive('set')->once()->with('msg:sent:idem-5', 'processing', 'EX', 86400, 'NX')->andReturn(true);
            $mock->shouldReceive('pipeline')->once()->andReturnUsing(function (Closure $callback) use ($key): array {
                $pipeline = Mockery::mock();
                $pipeline->shouldReceive('incr')->once()->with($key);
                $pipeline->shouldReceive('expire')->once()->with($key, 60);
                $callback($pipeline);

                return [1, true];
            });
            $mock->shouldReceive('set')->once()->with('msg:sent:idem-5', '1', 'EX', 86400);
        });
        $redis = $this->redisFactory($connection);

        $provider = new InMemoryProviderSender();
        $sender   = new MessageSender($this->registry($provider), $redis, 30, $this->nullConversationLogger(), new ConversationCaptureFactory());

        $sender->send($this->message(idempotencyKey: 'idem-5'));
    }

    public function test_successful_delivery_with_capture_context_logs_outbound_transcript(): void
    {
        $connection = $this->mock(Connection::class, function (MockInterface $mock): void {
            $mock->shouldReceive('set')->once()->with('msg:sent:idem-6', 'processing', 'EX', 86400, 'NX')->andReturn(true);
            $mock->shouldReceive('pipeline')->once()->andReturn([1, true]);
            $mock->shouldReceive('set')->once()->with('msg:sent:idem-6', '1', 'EX', 86400);
        });
        $redis = $this->redisFactory($connection);

        $logger   = new RecordingConversationLogger();
        $provider = new InMemoryProviderSender();
        $sender   = new MessageSender($this->registry($provider), $redis, 30, $logger, new ConversationCaptureFactory());

        $message = new OutboundMessage(
            idempotencyKey: 'idem-6',
            tenantId: 'tenant-1',
            channelId: 'channel-1',
            channelType: 'telegram',
            transportToken: 'bot-token',
            chatId: 'chat-1',
            payload: new MessagePayload(type: 'text', text: 'Reply'),
            metadata: [
                'contact_id'   => 'contact-1',
                'assistant_id' => 'assistant-1',
                'origin'       => 'flow',
                'origin_ref'   => ['flow_session_id' => 'sess-1', 'node_id' => 'n-1'],
            ],
        );

        $sender->send($message);

        $this->assertCount(1, $logger->entries);
        $entry = $logger->entries[0];
        $this->assertSame('contact-1', $entry->contactId);
        $this->assertSame('assistant-1', $entry->assistantId);
        $this->assertSame('provider-message-1', $entry->providerMessageId);
        $this->assertSame('Reply', $entry->text);
    }

    public function test_delivery_without_capture_context_logs_nothing(): void
    {
        $connection = $this->mock(Connection::class, function (MockInterface $mock): void {
            $mock->shouldReceive('set')->once()->with('msg:sent:idem-7', 'processing', 'EX', 86400, 'NX')->andReturn(true);
            $mock->shouldReceive('pipeline')->once()->andReturn([1, true]);
            $mock->shouldReceive('set')->once()->with('msg:sent:idem-7', '1', 'EX', 86400);
        });
        $redis = $this->redisFactory($connection);

        $logger   = new RecordingConversationLogger();
        $provider = new InMemoryProviderSender();
        $sender   = new MessageSender($this->registry($provider), $redis, 30, $logger, new ConversationCaptureFactory());

        // Bare message (no contact/assistant metadata) — e.g. an internal system
        // message that is not part of a contact transcript.
        $sender->send($this->message(idempotencyKey: 'idem-7'));

        $this->assertCount(0, $logger->entries);
    }

    private function nullConversationLogger(): ConversationLoggerInterface
    {
        return new RecordingConversationLogger();
    }

    private function redisFactory(Connection $connection): RedisFactory
    {
        return $this->mock(RedisFactory::class, function (MockInterface $mock) use ($connection): void {
            $mock->shouldReceive('connection')->andReturn($connection);
        });
    }

    private function registry(?ProviderSenderInterface $provider): ChannelRegistryInterface
    {
        return $this->mock(ChannelRegistryInterface::class, function (MockInterface $mock) use ($provider): void {
            $mock->shouldReceive('sender')->andReturn($provider);
        });
    }

    private function message(string $idempotencyKey): OutboundMessage
    {
        return new OutboundMessage(
            idempotencyKey: $idempotencyKey,
            tenantId: 'tenant-1',
            channelId: 'channel-1',
            channelType: 'telegram',
            transportToken: 'bot-token',
            chatId: 'chat-1',
            payload: new MessagePayload(type: 'text', text: 'Hello'),
        );
    }
}

final class RecordingConversationLogger implements ConversationLoggerInterface
{
    /** @var list<MessageLogEntry> */
    public array $entries = [];

    public function log(MessageLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function updateDeliveryStatus(string $providerMessageId, DeliveryStatus $status): void
    {
    }
}

final class InMemoryProviderSender implements ProviderSenderInterface
{
    public int $calls = 0;

    public function deliver(OutboundMessage $message): DeliveryResult
    {
        $this->calls++;

        return new DeliveryResult(sent: true, providerMessageId: 'provider-message-1');
    }
}
