<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Messaging\Telegram;

use App\Domains\Channels\Telegram\TelegramBotApiClientFactory;
use App\Domains\Channels\Telegram\TelegramDeliveryResolver;
use App\Domains\Channels\Telegram\TelegramDocumentDeliveryAction;
use App\Domains\Channels\Telegram\TelegramPhotoDeliveryAction;
use App\Domains\Channels\Telegram\TelegramSender;
use App\Domains\Channels\Telegram\TelegramTextDeliveryAction;
use FAPost\Foundation\Messaging\MessagePayload;
use FAPost\Foundation\Messaging\OutboundMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TelegramSenderTest extends TestCase
{
    public function test_text_payload_maps_to_send_message(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok'     => true,
                'result' => ['message_id' => 77],
            ], 200),
        ]);

        $sender = $this->sender();

        $result = $sender->deliver(new OutboundMessage(
            idempotencyKey: 'idem-1',
            tenantId: 'tenant-1',
            channelId: 'channel-1',
            channelType: 'telegram',
            transportToken: 'bot-token',
            chatId: 'chat-1',
            payload: new MessagePayload(type: 'text', text: 'Hello world'),
        ));

        $this->assertTrue($result->sent);
        $this->assertSame('77', $result->providerMessageId);
        Http::assertSent(static fn (Request $request): bool => 'https://api.telegram.org/botbot-token/sendMessage' === (string) $request->url()
                && 'chat-1' === $request['chatId']
                && 'Hello world' === $request['text']);
    }

    public function test_api_error_returns_unsent_delivery_result(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok'          => false,
                'description' => 'telegram down',
            ], 200),
        ]);

        $sender = $this->sender();

        $result = $sender->deliver(new OutboundMessage(
            idempotencyKey: 'idem-2',
            tenantId: 'tenant-1',
            channelId: 'channel-1',
            channelType: 'telegram',
            transportToken: 'bot-token',
            chatId: 'chat-1',
            payload: new MessagePayload(type: 'text', text: 'hello'),
        ));

        $this->assertFalse($result->sent);
        $this->assertSame('telegram down', $result->error);
    }

    private function sender(): TelegramSender
    {
        return new TelegramSender(
            new TelegramBotApiClientFactory(),
            new TelegramDeliveryResolver([
                new TelegramTextDeliveryAction(),
                new TelegramPhotoDeliveryAction(),
                new TelegramDocumentDeliveryAction(),
            ]),
        );
    }
}
