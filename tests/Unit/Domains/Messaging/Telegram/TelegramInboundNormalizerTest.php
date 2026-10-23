<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Messaging\Telegram;

use App\Domains\Channels\Telegram\Exceptions\UnsupportedUpdateTypeException;
use App\Domains\Channels\Telegram\TelegramInboundNormalizer;
use Fapost\Foundation\DTO\IncomingMessageType;
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

    public function test_a_phone_the_sender_shares_about_themselves_is_exposed_as_the_contact_phone(): void
    {
        $own = (new TelegramInboundNormalizer())->normalize($this->sharedContact(senderId: 202, contactUserId: 202));
        $foreign = (new TelegramInboundNormalizer())->normalize($this->sharedContact(senderId: 202, contactUserId: 303));

        $this->assertSame(IncomingMessageType::Contact, $own->type);
        $this->assertSame('+380501112233', $own->payload['phone']);
        $this->assertArrayNotHasKey('phone', $foreign->payload);
        $this->assertSame('+380501112233', $foreign->payload['contact']['phone_number']);
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

    /**
     * @return array<string, mixed>
     */
    private function sharedContact(int $senderId, int $contactUserId): array
    {
        return [
            'update_id' => 5002,
            'message'   => [
                'message_id' => 9002,
                'chat'       => ['id' => 101],
                'from'       => ['id' => $senderId],
                'contact'    => ['phone_number' => '+380501112233', 'first_name' => 'Ivan', 'user_id' => $contactUserId],
            ],
        ];
    }
}
