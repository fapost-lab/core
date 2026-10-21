<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\TelegramBotApiClientFactory;
use App\Domains\Channels\Telegram\TelegramDeliveryResolver;
use App\Domains\Channels\Telegram\TelegramReplyMarkup;
use App\Domains\Channels\Telegram\TelegramSender;
use App\Domains\Channels\Telegram\TelegramTextDeliveryAction;
use Fapost\Foundation\Messaging\Button;
use Fapost\Foundation\Messaging\ButtonAction;
use Fapost\Foundation\Messaging\ButtonSet;
use Fapost\Foundation\Messaging\MessagePayload;
use Fapost\Foundation\Messaging\OutboundMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TelegramReplyMarkupTest extends TestCase
{
    public function test_callback_buttons_become_an_inline_keyboard(): void
    {
        $markup = TelegramReplyMarkup::fromButtons(new ButtonSet([
            [new Button('Yes', payload: 'p-1'), new Button('No', payload: 'p-2')],
            [new Button('Maybe', payload: 'p-3')],
        ]));

        $this->assertSame([
            'inline_keyboard' => [
                [['text' => 'Yes', 'callback_data' => 'p-1'], ['text' => 'No', 'callback_data' => 'p-2']],
                [['text' => 'Maybe', 'callback_data' => 'p-3']],
            ],
        ], $markup);
    }

    public function test_keyed_rows_still_encode_as_json_arrays(): void
    {
        $markup = TelegramReplyMarkup::fromButtons(new ButtonSet([
            2 => ['a' => new Button('Yes', payload: 'p-1')],
            5 => [new Button('No', payload: 'p-2')],
        ]));

        $this->assertSame(
            '{"inline_keyboard":[[{"text":"Yes","callback_data":"p-1"}],[{"text":"No","callback_data":"p-2"}]]}',
            json_encode($markup, JSON_THROW_ON_ERROR),
        );
    }

    public function test_reply_buttons_become_a_one_time_reply_keyboard(): void
    {
        $markup = TelegramReplyMarkup::fromButtons(new ButtonSet([
            [new Button('One', ButtonAction::Reply), new Button('Two', ButtonAction::Reply)],
            [new Button('Three', ButtonAction::Reply)],
        ]));

        $this->assertSame([
            'keyboard'          => [[['text' => 'One'], ['text' => 'Two']], [['text' => 'Three']]],
            'one_time_keyboard' => true,
            'resize_keyboard'   => true,
        ], $markup);
    }

    public function test_share_buttons_request_the_contact_and_the_location(): void
    {
        $markup = TelegramReplyMarkup::fromButtons(new ButtonSet([
            [new Button('Contact', ButtonAction::ShareContact)],
            [new Button('Location', ButtonAction::ShareLocation)],
        ]));

        $this->assertSame([
            'keyboard'          => [[['text' => 'Contact', 'request_contact' => true]], [['text' => 'Location', 'request_location' => true]]],
            'one_time_keyboard' => true,
            'resize_keyboard'   => true,
        ], $markup);
    }

    public function test_the_text_action_sends_the_translated_buttons(): void
    {
        $this->deliver(new MessagePayload(
            type: 'keyboard',
            text: 'Choose',
            keyboard: ['keyboard' => [[['text' => 'Legacy']]]],
            buttons: new ButtonSet([[new Button('Yes', payload: 'p-1')]]),
        ));

        Http::assertSent(static fn (Request $request): bool => ['inline_keyboard' => [[['text' => 'Yes', 'callback_data' => 'p-1']]]] === $request['reply_markup']);
    }

    public function test_the_text_action_still_sends_legacy_raw_markup_without_buttons(): void
    {
        $this->deliver(new MessagePayload(type: 'keyboard', text: 'Choose', keyboard: ['keyboard' => [[['text' => 'Legacy']]]]));

        Http::assertSent(static fn (Request $request): bool => ['keyboard' => [[['text' => 'Legacy']]]] === $request['reply_markup']);
    }

    public function test_the_text_action_sends_no_markup_without_buttons_or_keyboard(): void
    {
        $this->deliver(new MessagePayload(type: 'text', text: 'Hello'));

        Http::assertSent(static fn (Request $request): bool => ! isset($request['reply_markup']));
    }

    private function deliver(MessagePayload $payload): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        $result = (new TelegramSender(
            new TelegramBotApiClientFactory(),
            new TelegramDeliveryResolver([new TelegramTextDeliveryAction()]),
        ))->deliver(new OutboundMessage(
            idempotencyKey: 'idem',
            tenantId: 'tenant-1',
            channelId: 'channel-1',
            channelType: 'telegram',
            transportToken: 'bot-token',
            chatId: 'chat-1',
            payload: $payload,
        ));

        $this->assertTrue($result->sent);
    }
}
