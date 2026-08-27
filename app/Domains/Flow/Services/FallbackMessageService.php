<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Flow\Contracts\FallbackMessageServiceInterface;
use FAPost\Foundation\Messaging\MessagePayload;
use FAPost\Foundation\Messaging\MessageSenderInterface as OutboundMessageSenderInterface;
use FAPost\Foundation\Messaging\OutboundMessage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends a plain-text message to a contact using the active channel for the given assistant,
 * without requiring an existing flow session.
 */
final readonly class FallbackMessageService implements FallbackMessageServiceInterface
{
    public function __construct(
        private OutboundMessageSenderInterface $sender,
    ) {
    }

    public function send(Contact $contact, string $assistantId, string $text): void
    {
        try {
            $channelContact = ChannelContact::query()
                ->select('channel_contacts.*')
                ->join('channels', 'channels.id', '=', 'channel_contacts.channel_id')
                ->where('channel_contacts.contact_id', $contact->getKey())
                ->where('channels.assistant_id', $assistantId)
                ->where('channels.is_active', true)
                ->with(['channel', 'contact'])
                ->orderByDesc('channel_contacts.last_interaction_at')
                ->first();

            if (
                ! $channelContact instanceof ChannelContact
                || ! $channelContact->channel instanceof Channel
                || null === $channelContact->contact
            ) {
                return;
            }

            $channel = $channelContact->channel;

            $this->sender->send(
                new OutboundMessage(
                    idempotencyKey: 'fallback:' . (string)$contact->getKey() . ':' . Str::ulid(),
                    tenantId: (string)$contact->tenant_id,
                    channelId: (string)$channel->getKey(),
                    channelType: $channel->type->value,
                    transportToken: $channel->token,
                    chatId: (string)$channelContact->contact->external_id,
                    payload: new MessagePayload(
                        type: 'text',
                        text: $text,
                    ),
                    // contact_id / assistant_id are what let the capture funnel
                    // recognise this as part of a transcript — without them the
                    // message is silently dropped from the log, and an operator
                    // opening the thread would see the contact talking to
                    // nobody where the busy/fallback reply actually went out.
                    metadata: [
                        'parse_mode'   => 'HTML',
                        'contact_id'   => (string)$contact->getKey(),
                        'assistant_id' => $assistantId,
                        'origin'       => MessageOrigin::System->value,
                    ],
                )
            );
        } catch (Throwable) {
            // Best-effort: never block incoming message processing on a fallback send failure.
        }
    }
}
