<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Conversation;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Contracts\ConversationReplyServiceInterface;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Conversation\Exceptions\ConversationReplyUndeliverableException;
use App\Domains\Conversation\Models\Conversation;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessageSenderInterface as OutboundMessageSenderInterface;
use Fapost\Foundation\Messaging\OutboundMessage;
use Mockery\MockInterface;
use Tests\Feature\FeatureTestCase;

/**
 * Covers {@see \App\Domains\Conversation\Services\ConversationReplyService} —
 * the envelope it hands to {@see \App\Domains\Messaging\MessageSender} must
 * carry staff attribution so {@see \App\Domains\Conversation\Capture\ConversationCaptureFactory}
 * (covered separately in ConversationCaptureFactoryTest) logs it as a staff
 * reply, and it must send on the thread's own channel/chat, not a guessed one.
 */
final class ConversationReplyServiceTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_send_builds_staff_attributed_outbound_message_on_thread_channel(): void
    {
        [$conversation, $contact, $channel] = $this->threadWithLinkedContact();

        $this->mock(OutboundMessageSenderInterface::class, function (MockInterface $mock) use ($conversation, $contact, $channel): void {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(function (OutboundMessage $message) use ($conversation, $contact, $channel): bool {
                    return $message->chatId === $contact->external_id
                        && $message->channelId === (string) $channel->getKey()
                        && $message->channelType === ChannelTypeEnum::Telegram->value
                        && $message->payload->text === 'Thanks for reaching out'
                        && $message->metadata['contact_id'] === (string) $conversation->contact_id
                        && $message->metadata['assistant_id'] === (string) $conversation->assistant_id
                        && $message->metadata['origin'] === MessageOrigin::Staff->value
                        && ($message->metadata['origin_ref']['staff_user_id'] ?? null) === 'staff-1'
                        // Operator prose must not be sent as HTML markup: a bare
                        // "R&D" or "5 < 10" would be malformed and Telegram would
                        // reject the whole message.
                        && ! array_key_exists('parse_mode', $message->metadata);
                })
                ->andReturn(new DeliveryResult(sent: true, providerMessageId: 'provider-1'));
        });

        $result = app(ConversationReplyServiceInterface::class)->send($conversation, 'Thanks for reaching out', 'staff-1');

        $this->assertTrue($result->sent);
        $this->assertSame('provider-1', $result->providerMessageId);
    }

    public function test_send_throws_when_contact_has_no_channel_linkage(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create(['external_id' => 'chat-999']);
        $channel   = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'is_active'    => true,
        ]));

        // No ChannelContact linkage created — the reply has nowhere to go.
        $conversation = Conversation::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $assistant->getKey(),
            'contact_id'   => $contact->getKey(),
            'channel_id'   => $channel->getKey(),
            'platform'     => 'telegram',
        ]);

        $this->expectException(ConversationReplyUndeliverableException::class);

        app(ConversationReplyServiceInterface::class)->send($conversation, 'hi', 'staff-1');
    }

    public function test_send_throws_when_channel_is_inactive(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create(['external_id' => 'chat-998']);
        $channel   = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'is_active'    => false,
        ]));

        ChannelContact::query()->create([
            'contact_id'          => $contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => now(),
        ]);

        $conversation = Conversation::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $assistant->getKey(),
            'contact_id'   => $contact->getKey(),
            'channel_id'   => $channel->getKey(),
            'platform'     => 'telegram',
        ]);

        $this->expectException(ConversationReplyUndeliverableException::class);

        app(ConversationReplyServiceInterface::class)->send($conversation, 'hi', 'staff-1');
    }

    /**
     * @return array{0: Conversation, 1: Contact, 2: Channel}
     */
    private function threadWithLinkedContact(): array
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create(['external_id' => 'chat-123']);
        $channel   = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));

        ChannelContact::query()->create([
            'contact_id'          => $contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => now(),
        ]);

        $conversation = Conversation::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $assistant->getKey(),
            'contact_id'   => $contact->getKey(),
            'channel_id'   => $channel->getKey(),
            'platform'     => 'telegram',
        ]);

        return [$conversation, $contact, $channel];
    }
}
