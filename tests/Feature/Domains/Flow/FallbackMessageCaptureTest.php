<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Capture\ConversationCaptureFactory;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Flow\Services\FallbackMessageService;
use FAPost\Foundation\Messaging\DeliveryResult;
use FAPost\Foundation\Messaging\MessageSenderInterface;
use FAPost\Foundation\Messaging\OutboundMessage;
use Tests\Feature\FeatureTestCase;

/**
 * Busy / fallback replies are the messages a contact sees when the assistant
 * cannot answer — precisely the ones an operator needs to see in the thread
 * before taking over. They travel the same outbound funnel as everything else,
 * and the capture factory identifies transcript-worthy messages purely by the
 * `contact_id` / `assistant_id` metadata keys: without them the message is
 * dropped from the log without a trace.
 */
final class FallbackMessageCaptureTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_fallback_send_carries_the_metadata_the_transcript_capture_requires(): void
    {
        $channel = $this->channel();
        $contact = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $this->bind($contact, $channel);

        $sender = $this->capturingSender();

        (new FallbackMessageService($sender))->send(
            $contact,
            (string) $channel->assistant_id,
            'The assistant is busy right now.',
        );

        $sent = $sender->sent;

        $this->assertInstanceOf(OutboundMessage::class, $sent);
        $this->assertSame((string) $contact->getKey(), $sent->metadata['contact_id'] ?? null);
        $this->assertSame((string) $channel->assistant_id, $sent->metadata['assistant_id'] ?? null);
        $this->assertSame(MessageOrigin::System->value, $sent->metadata['origin'] ?? null);
    }

    /**
     * The assertion that actually matters: the capture factory accepts it.
     * Asserting the metadata keys alone would pass even if the factory started
     * requiring something else.
     */
    public function test_capture_factory_turns_a_fallback_send_into_a_log_entry(): void
    {
        $channel = $this->channel();
        $contact = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $this->bind($contact, $channel);

        $sender = $this->capturingSender();

        (new FallbackMessageService($sender))->send($contact, (string) $channel->assistant_id, 'Busy.');

        $entry = (new ConversationCaptureFactory())->forOutbound(
            $sender->sent,
            new DeliveryResult(sent: true, providerMessageId: 'provider-1'),
        );

        $this->assertNotNull($entry, 'Fallback message was dropped by the transcript capture.');
        $this->assertSame((string) $contact->getKey(), $entry->contactId);
        $this->assertSame(MessageOrigin::System, $entry->origin);
        $this->assertSame('Busy.', $entry->text);
    }

    private function capturingSender(): MessageSenderInterface
    {
        return new class () implements MessageSenderInterface {
            public ?OutboundMessage $sent = null;

            public function send(OutboundMessage $message): DeliveryResult
            {
                $this->sent = $message;

                return new DeliveryResult(sent: true, providerMessageId: 'provider-1');
            }
        };
    }

    private function channel(): Channel
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID, 'default_language' => 'en']);

        return Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->id,
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));
    }

    private function bind(Contact $contact, Channel $channel): void
    {
        ChannelContact::query()->create([
            'contact_id'          => $contact->id,
            'channel_id'          => $channel->id,
            'last_interaction_at' => now(),
        ]);
    }
}
