<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Channels\Telegram\TelegramAdapter;
use App\Domains\Channels\Telegram\TelegramInboundNormalizer;
use Fapost\Foundation\DTO\IncomingMessageType;
use Tests\TestCase;

final class TelegramChannelAdapterTest extends TestCase
{
    public function test_normalize_uses_callback_sender_over_message_sender_and_normalizes_types(): void
    {
        $rawPayload = [
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
        ];

        $message = $this->adapter()->normalize($rawPayload);

        $this->assertSame('101', $message->updateId);
        $this->assertSame('123456', $message->externalChatId);
        $this->assertSame('222', $message->externalUserId);
        $this->assertSame('btn:ok', $message->text);
        $this->assertSame(IncomingMessageType::CallbackQuery, $message->type);
        $this->assertSame('telegram', $message->platform);
    }

    public function test_normalize_parses_plain_text_message(): void
    {
        $rawPayload = [
            'update_id' => 200,
            'message'   => [
                'message_id' => 100,
                'date'       => 1_717_171_717,
                'chat'       => ['id' => 10],
                'from'       => ['id' => 20, 'username' => 'user20'],
                'text'       => 'hello world',
            ],
        ];

        $message = $this->adapter()->normalize($rawPayload);

        $this->assertSame('200', $message->updateId);
        $this->assertSame('20', $message->externalUserId);
        $this->assertSame('10', $message->externalChatId);
        $this->assertSame('hello world', $message->text);
        $this->assertSame(IncomingMessageType::Text, $message->type);
    }

    private function adapter(): TelegramAdapter
    {
        return new TelegramAdapter(new TelegramInboundNormalizer());
    }
}
