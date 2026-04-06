<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Webhook\Adapters\TelegramChannelAdapter;
use App\Domains\Webhook\Exceptions\InvalidSignatureException;
use Illuminate\Http\Request;
use Tests\TestCase;

final class TelegramChannelAdapterTest extends TestCase
{
    public function test_verify_signature_throws_when_secret_token_does_not_match(): void
    {
        $adapter = new TelegramChannelAdapter();
        $request = Request::create('/', 'POST', server: ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'wrong']);

        $this->expectException(InvalidSignatureException::class);

        $adapter->verifySignature($request, 'expected');
    }

    public function test_parse_uses_callback_sender_over_message_sender_and_normalizes_platform_enum(): void
    {
        $adapter = new TelegramChannelAdapter();
        $request = Request::create(
            uri: '/',
            method: 'POST',
            content: json_encode([
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
            ], JSON_THROW_ON_ERROR),
            server: ['CONTENT_TYPE' => 'application/json'],
        );

        $message = $adapter->parse($request);

        $this->assertSame('101', $message->updateId);
        $this->assertSame('999', $message->externalChatId);
        $this->assertSame('222', $message->externalUserId);
        $this->assertSame('btn:ok', $message->text);
        $this->assertSame('callback_query', $message->messageType);
        $this->assertSame(1_717_171_717, $message->timestamp);
        $this->assertSame(PlatformEnum::Telegram, $message->platform);
        $this->assertSame('callback_user', $message->meta['username']);
    }
}
