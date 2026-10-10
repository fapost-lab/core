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
use Carbon\CarbonImmutable;
use Closure;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessagePayload;
use Fapost\Foundation\Messaging\OutboundMessage;
use Fapost\Foundation\Messaging\ProviderSenderInterface;
use Fapost\Foundation\Quota\DTO\UsageDecision;
use Fapost\Foundation\Quota\Exceptions\VolumeLimitReachedException;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\FakeUsageMeter;
use Tests\Support\UsageGates;
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
        $sender   = new MessageSender($this->registry($provider), $redis, 30, $this->nullConversationLogger(), new ConversationCaptureFactory(), UsageGates::gate());

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
        $sender   = new MessageSender($this->registry($provider), $redis, 30, $this->nullConversationLogger(), new ConversationCaptureFactory(), UsageGates::gate());

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

        $sender = new MessageSender($this->registry(null), $redis, 30, $this->nullConversationLogger(), new ConversationCaptureFactory(), UsageGates::gate());

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
        $sender   = new MessageSender($this->registry($provider), $redis, 30, $this->nullConversationLogger(), new ConversationCaptureFactory(), UsageGates::gate());

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
        $sender   = new MessageSender($this->registry($provider), $redis, 30, $this->nullConversationLogger(), new ConversationCaptureFactory(), UsageGates::gate());

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
        $sender   = new MessageSender($this->registry($provider), $redis, 30, $logger, new ConversationCaptureFactory(), UsageGates::gate());

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
        $sender   = new MessageSender($this->registry($provider), $redis, 30, $logger, new ConversationCaptureFactory(), UsageGates::gate());

        // Bare message (no contact/assistant metadata) — e.g. an internal system
        // message that is not part of a contact transcript.
        $sender->send($this->message(idempotencyKey: 'idem-7'));

        $this->assertCount(0, $logger->entries);
    }

    public function test_a_refused_volume_never_reaches_the_provider_and_releases_the_idempotency_key(): void
    {
        $connection = $this->mock(Connection::class, function (MockInterface $mock): void {
            $mock->shouldReceive('set')->once()->with('msg:sent:idem-8', 'processing', 'EX', 86400, 'NX')->andReturn(true);
            $mock->shouldReceive('pipeline')->once()->andReturn([1, true]);
            $mock->shouldReceive('del')->once()->with('msg:sent:idem-8');
        });
        $provider = new InMemoryProviderSender();
        $meter    = FakeUsageMeter::denying(1000, 1000);
        $sender   = new MessageSender($this->registry($provider), $this->redisFactory($connection), 30, $this->nullConversationLogger(), new ConversationCaptureFactory(), UsageGates::gate($meter));

        try {
            $sender->send($this->message(idempotencyKey: 'idem-8'));
            $this->fail('Expected VolumeLimitReachedException.');
        } catch (VolumeLimitReachedException $exception) {
            $this->assertSame('outbound_messages', $exception->key);
            $this->assertSame(1000, $exception->limit);
            $this->assertSame(1000, $exception->used);
            $this->assertSame('Limit reached.', $exception->getMessage());
        }

        $this->assertSame(0, $provider->calls);
        $this->assertCount(1, $meter->units);
        $this->assertSame('msg:idem-8', $meter->units[0]->unitKey);
        $this->assertSame('outbound_messages', $meter->units[0]->key);
    }

    public function test_a_refusal_without_an_operator_text_gets_a_readable_message(): void
    {
        $meter = new FakeUsageMeter(UsageDecision::refused(1000, 1000));

        try {
            UsageGates::gate($meter)->admitKey('idem');
            $this->fail('Expected VolumeLimitReachedException.');
        } catch (VolumeLimitReachedException $exception) {
            $this->assertSame('Outbound messages limit reached: 1000 of 1000 this period.', $exception->getMessage());
        }
    }

    public function test_a_duplicate_does_not_spend_volume(): void
    {
        $connection = $this->mock(Connection::class, function (MockInterface $mock): void {
            $mock->shouldReceive('set')->once()->with('msg:sent:idem-9', 'processing', 'EX', 86400, 'NX')->andReturn(false);
        });
        $meter  = FakeUsageMeter::denying();
        $sender = new MessageSender($this->registry(new InMemoryProviderSender()), $this->redisFactory($connection), 30, $this->nullConversationLogger(), new ConversationCaptureFactory(), UsageGates::gate($meter));

        $result = $sender->send($this->message(idempotencyKey: 'idem-9'));

        $this->assertTrue($result->duplicate);
        $this->assertSame([], $meter->units);
    }

    public function test_rate_limited_and_unsupported_sends_do_not_spend_volume(): void
    {
        $meter      = FakeUsageMeter::allowing();
        $connection = $this->mock(Connection::class, function (MockInterface $mock): void {
            $mock->shouldReceive('set')->twice()->andReturn(true);
            $mock->shouldReceive('pipeline')->twice()->andReturn([31, true], [1, true]);
            $mock->shouldReceive('del')->twice();
        });

        $limited = new MessageSender($this->registry(new InMemoryProviderSender()), $this->redisFactory($connection), 30, $this->nullConversationLogger(), new ConversationCaptureFactory(), UsageGates::gate($meter));

        try {
            $limited->send($this->message(idempotencyKey: 'a'));
            $this->fail('Expected RateLimitExceededException.');
        } catch (RateLimitExceededException) {
        }

        $unsupported = new MessageSender($this->registry(null), $this->redisFactory($connection), 30, $this->nullConversationLogger(), new ConversationCaptureFactory(), UsageGates::gate($meter));

        try {
            $unsupported->send($this->message(idempotencyKey: 'b'));
            $this->fail('Expected UnsupportedChannelException.');
        } catch (UnsupportedChannelException) {
        }

        $this->assertSame([], $meter->units);
    }

    public function test_edits_of_a_sent_message_do_not_spend_volume(): void
    {
        $meter = FakeUsageMeter::denying();
        $gate  = UsageGates::gate($meter);

        foreach ([
            new MessagePayload(type: 'remove_keyboard', text: ''),
            new MessagePayload(type: 'text', text: 'edited'),
        ] as $index => $payload) {
            $gate->admit(new OutboundMessage(
                idempotencyKey: "edit-{$index}",
                tenantId: 'tenant-1',
                channelId: 'channel-1',
                channelType: 'telegram',
                transportToken: null,
                chatId: 'chat-1',
                payload: $payload,
                metadata: 0 === $index ? [] : ['edit_message_id' => '77'],
            ));
        }

        $this->assertSame([], $meter->units);
    }

    public function test_a_unit_key_longer_than_the_contract_allows_is_hashed(): void
    {
        $meter = FakeUsageMeter::allowing();
        $long  = str_repeat('k', 300);

        UsageGates::gate($meter)->admitKey($long);
        UsageGates::gate($meter)->admitKey($long);

        $this->assertSame('msg:' . hash('sha256', $long), $meter->units[0]->unitKey);
        $this->assertLessThanOrEqual(191, mb_strlen($meter->units[0]->unitKey));
        $this->assertSame($meter->units[0]->unitKey, $meter->units[1]->unitKey);
    }

    public function test_the_time_is_now_unless_the_message_carries_a_stable_one(): void
    {
        CarbonImmutable::setTestNow('2026-10-20 10:00:00');
        $meter = FakeUsageMeter::allowing();
        $gate  = UsageGates::gate($meter);

        $gate->admit($this->message(idempotencyKey: 'now'));
        $gate->admit(new OutboundMessage(
            idempotencyKey: 'stable',
            tenantId: 'tenant-1',
            channelId: 'channel-1',
            channelType: 'telegram',
            transportToken: null,
            chatId: 'chat-1',
            payload: new MessagePayload(type: 'text', text: 'Hello'),
            metadata: ['volume_occurred_at' => '2026-09-30T23:59:00+00:00'],
        ));

        $this->assertSame('2026-10-20 10:00:00', $meter->units[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30 23:59:00', $meter->units[1]->occurredAt->format('Y-m-d H:i:s'));

        CarbonImmutable::setTestNow();
    }

    public function test_without_an_operator_a_send_touches_redis_only_for_its_own_keys(): void
    {
        // The strict mock allows exactly the idempotency reservation, the rate counter and the sent mark.
        $connection = $this->mock(Connection::class, function (MockInterface $mock): void {
            $mock->shouldReceive('set')->once()->with('msg:sent:idem-10', 'processing', 'EX', 86400, 'NX')->andReturn(true);
            $mock->shouldReceive('pipeline')->once()->andReturn([1, true]);
            $mock->shouldReceive('set')->once()->with('msg:sent:idem-10', '1', 'EX', 86400);
        });
        $provider = new InMemoryProviderSender();
        $sender   = new MessageSender($this->registry($provider), $this->redisFactory($connection), 30, $this->nullConversationLogger(), new ConversationCaptureFactory(), UsageGates::gate());

        $this->assertTrue($sender->send($this->message(idempotencyKey: 'idem-10'))->sent);
        $this->assertSame(1, $provider->calls);
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
