<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Messaging\Telegram;

use App\Domains\Channels\Telegram\Exceptions\UnsupportedUpdateTypeException;
use App\Domains\Channels\Telegram\TelegramInboundNormalizer;
use FAPost\Foundation\DTO\IncomingMessageType;
use Tests\TestCase;

final class TelegramInboundNormalizerTest extends TestCase
{
    public function test_message_update_is_normalized_correctly(): void
    {
        $normalizer = new TelegramInboundNormalizer();

        $message = $normalizer->normalize([
            'update_id' => 5001,
            'message'   => [
                'message_id' => 9001,
                'chat'       => ['id' => 101],
                'from'       => ['id' => 202],
                'text'       => 'hello',
            ],
        ]);

        $this->assertSame('5001', $message->updateId);
        $this->assertSame('101', $message->externalChatId);
        $this->assertSame('202', $message->externalUserId);
        $this->assertSame('hello', $message->text);
        $this->assertSame(IncomingMessageType::Text, $message->type);
        $this->assertSame('9001', $message->payload['message_id']);
    }

    public function test_callback_query_update_is_normalized_correctly(): void
    {
        $normalizer = new TelegramInboundNormalizer();

        $message = $normalizer->normalize([
            'update_id'      => 5002,
            'callback_query' => [
                'data'    => 'btn:ok',
                'from'    => ['id' => 303],
                'message' => [
                    'message_id' => 9002,
                    'chat'       => ['id' => 102],
                ],
            ],
        ]);

        $this->assertSame('5002', $message->updateId);
        $this->assertSame('102', $message->externalChatId);
        $this->assertSame('303', $message->externalUserId);
        $this->assertSame('btn:ok', $message->text);
        $this->assertSame(IncomingMessageType::CallbackQuery, $message->type);
        $this->assertSame('9002', $message->payload['message_id']);
    }

    public function test_unknown_type_throws_exception(): void
    {
        $normalizer = new TelegramInboundNormalizer();

        $this->expectException(UnsupportedUpdateTypeException::class);

        $normalizer->normalize(['update_id' => 9999]);
    }
}
