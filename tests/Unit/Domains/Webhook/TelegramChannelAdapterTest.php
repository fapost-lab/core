<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Webhook\Adapters\TelegramChannelAdapter;
use FAPost\Foundation\DTO\IncomingMessageType;
use Tests\TestCase;

final class TelegramChannelAdapterTest extends TestCase
{
    public function test_platform_returns_telegram_enum(): void
    {
        $adapter = new TelegramChannelAdapter();

        $this->assertSame(PlatformEnum::Telegram, $adapter->platform());
    }

    public function test_verify_signature_returns_false_when_secret_token_does_not_match(): void
    {
        $adapter = new TelegramChannelAdapter();

        $result = $adapter->verifySignature(
            headers: ['x-telegram-bot-api-secret-token' => 'wrong'],
            body: '{}',
            secret: 'expected',
        );

        $this->assertFalse($result);
    }

    public function test_verify_signature_returns_true_when_secret_token_matches(): void
    {
        $adapter = new TelegramChannelAdapter();

        $result = $adapter->verifySignature(
            headers: ['x-telegram-bot-api-secret-token' => 'expected'],
            body: '{}',
            secret: 'expected',
        );

        $this->assertTrue($result);
    }

    public function test_parse_incoming_uses_callback_sender_over_message_sender_and_normalizes_types(): void
    {
        $adapter = new TelegramChannelAdapter();
        $body    = json_encode([
            'update_id' => 101,
            'message'   => [
                'message_id' => 2001,
                'date'       => 1_717_171_717,
                'chat'       => ['id' => 999],
                'from'       => [
                    'id'         => 111,
                    'username'   => 'message_user',
                    'first_name' => 'Message',
                ],
                'text' => 'message text',
            ],
            'callback_query' => [
                'id'   => 'cb-1',
                'from' => [
                    'id'         => 222,
                    'username'   => 'callback_user',
                    'first_name' => 'Callback',
                    'last_name'  => 'Sender',
                ],
                'message' => [
                    'message_id' => 2001,
                    'date'       => 1_717_171_718,
                    'chat'       => ['id' => 123456],
                ],
                'data' => 'btn:ok',
            ],
        ], JSON_THROW_ON_ERROR);

        $message = $adapter->parseIncoming($body);

        $this->assertSame('101', $message->updateId);
        $this->assertSame('999', $message->externalChatId);
        $this->assertSame('222', $message->externalUserId);
        $this->assertSame('btn:ok', $message->text);
        $this->assertSame(IncomingMessageType::CallbackQuery, $message->type);
        $this->assertSame('telegram', $message->platform);
        $this->assertSame('callback_user', $message->payload['username']);
    }
}
