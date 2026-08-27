<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Messaging\Telegram;

use App\Domains\Channels\Telegram\TelegramBotApiClientFactory;
use App\Domains\Channels\Telegram\TelegramDeliveryResolver;
use App\Domains\Channels\Telegram\TelegramDocumentDeliveryAction;
use App\Domains\Channels\Telegram\TelegramPhotoDeliveryAction;
use App\Domains\Channels\Telegram\TelegramSender;
use App\Domains\Channels\Telegram\TelegramTextDeliveryAction;
use App\Domains\Channels\Telegram\TelegramVideoDeliveryAction;
use App\Domains\Channels\Telegram\TelegramVoiceDeliveryAction;
use Fapost\Foundation\Messaging\MessagePayload;
use Fapost\Foundation\Messaging\OutboundMessage;
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
                && 'chat-1' === $request['chat_id']
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

    public function test_keyboard_payload_maps_reply_markup_to_send_message(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok'     => true,
                'result' => ['message_id' => 88],
            ], 200),
        ]);

        $sender = $this->sender();

        $result = $sender->deliver(new OutboundMessage(
            idempotencyKey: 'idem-3',
            tenantId: 'tenant-1',
            channelId: 'channel-1',
            channelType: 'telegram',
            transportToken: 'bot-token',
            chatId: 'chat-1',
            payload: new MessagePayload(
                type: 'keyboard',
                text: 'Choose',
                keyboard: ['keyboard' => [[['text' => 'Yes']]], 'one_time_keyboard' => true, 'resize_keyboard' => true],
            ),
        ));

        $this->assertTrue($result->sent);
        Http::assertSent(static fn (Request $request): bool => 'https://api.telegram.org/botbot-token/sendMessage' === (string) $request->url()
            && 'Choose' === $request['text']
            && true === $request['reply_markup']['one_time_keyboard']
            && 'Yes' === $request['reply_markup']['keyboard'][0][0]['text']);
    }

    public function test_photo_payload_maps_to_send_photo(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok'     => true,
                'result' => ['message_id' => 100],
            ], 200),
        ]);

        $result = $this->sender()->deliver(new OutboundMessage(
            idempotencyKey: 'idem-photo',
            tenantId: 'tenant-1',
            channelId: 'channel-1',
            channelType: 'telegram',
            transportToken: 'bot-token',
            chatId: 'chat-1',
            payload: new MessagePayload(
                type: 'photo',
                text: 'A caption',
                media: ['photo' => 'https://example.com/img.jpg'],
            ),
        ));

        $this->assertTrue($result->sent);
        $this->assertSame('100', $result->providerMessageId);
        Http::assertSent(static fn (Request $request): bool => str_contains((string) $request->url(), 'sendPhoto')
            && 'chat-1' === $request['chat_id']
            && 'https://example.com/img.jpg' === $request['photo']
            && 'A caption' === $request['caption']);
    }

    public function test_document_payload_maps_to_send_document(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok'     => true,
                'result' => ['message_id' => 101],
            ], 200),
        ]);

        $result = $this->sender()->deliver(new OutboundMessage(
            idempotencyKey: 'idem-doc',
            tenantId: 'tenant-1',
            channelId: 'channel-1',
            channelType: 'telegram',
            transportToken: 'bot-token',
            chatId: 'chat-1',
            payload: new MessagePayload(
                type: 'document',
                text: '',
                media: ['document' => 'https://example.com/file.pdf'],
            ),
        ));

        $this->assertTrue($result->sent);
        Http::assertSent(static fn (Request $request): bool => str_contains((string) $request->url(), 'sendDocument')
            && 'https://example.com/file.pdf' === $request['document']);
    }

    public function test_video_payload_maps_to_send_video(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok'     => true,
                'result' => ['message_id' => 102],
            ], 200),
        ]);

        $result = $this->sender()->deliver(new OutboundMessage(
            idempotencyKey: 'idem-video',
            tenantId: 'tenant-1',
            channelId: 'channel-1',
            channelType: 'telegram',
            transportToken: 'bot-token',
            chatId: 'chat-1',
            payload: new MessagePayload(
                type: 'video',
                text: 'Watch this',
                media: ['video' => 'https://example.com/clip.mp4'],
            ),
        ));

        $this->assertTrue($result->sent);
        Http::assertSent(static fn (Request $request): bool => str_contains((string) $request->url(), 'sendVideo')
            && 'https://example.com/clip.mp4' === $request['video']
            && 'Watch this' === $request['caption']);
    }

    public function test_voice_payload_maps_to_send_voice(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok'     => true,
                'result' => ['message_id' => 103],
            ], 200),
        ]);

        $result = $this->sender()->deliver(new OutboundMessage(
            idempotencyKey: 'idem-voice',
            tenantId: 'tenant-1',
            channelId: 'channel-1',
            channelType: 'telegram',
            transportToken: 'bot-token',
            chatId: 'chat-1',
            payload: new MessagePayload(
                type: 'voice',
                text: '',
                media: ['voice' => 'https://example.com/audio.ogg'],
            ),
        ));

        $this->assertTrue($result->sent);
        Http::assertSent(static fn (Request $request): bool => str_contains((string) $request->url(), 'sendVoice')
            && 'https://example.com/audio.ogg' === $request['voice']);
    }

    private function sender(): TelegramSender
    {
        return new TelegramSender(
            new TelegramBotApiClientFactory(),
            new TelegramDeliveryResolver([
                new TelegramTextDeliveryAction(),
                new TelegramPhotoDeliveryAction(),
                new TelegramDocumentDeliveryAction(),
                new TelegramVideoDeliveryAction(),
                new TelegramVoiceDeliveryAction(),
            ]),
        );
    }
}
