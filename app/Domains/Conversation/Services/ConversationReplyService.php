<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Services;

use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Conversation\Contracts\ConversationReplyServiceInterface;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Conversation\Exceptions\ConversationReplyUndeliverableException;
use App\Domains\Conversation\Models\Conversation;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessagePayload;
use Fapost\Foundation\Messaging\MessageSenderInterface as OutboundMessageSenderInterface;
use Fapost\Foundation\Messaging\OutboundMessage;
use Illuminate\Support\Str;

/**
 * Reply-from-inbox implementation. Sends on the thread's own channel — unlike
 * {@see \App\Domains\Flow\Services\FallbackMessageService}, which picks "the
 * assistant's active channel" because it has no thread to anchor to, an inbox
 * reply must go out on the exact channel the conversation happened on.
 */
final readonly class ConversationReplyService implements ConversationReplyServiceInterface
{
    public function __construct(
        private OutboundMessageSenderInterface $sender,
    ) {
    }

    public function send(Conversation $conversation, string $text, string $staffUserId): DeliveryResult
    {
        $channel = Channel::query()->find($conversation->channel_id);

        if (! $channel instanceof Channel || ! $channel->is_active) {
            throw new ConversationReplyUndeliverableException(
                "No active channel [{$conversation->channel_id}] for conversation [{$conversation->getKey()}].",
            );
        }

        $channelContact = ChannelContact::query()
            ->where('contact_id', $conversation->contact_id)
            ->where('channel_id', $conversation->channel_id)
            ->with('contact')
            ->first();

        if (! $channelContact instanceof ChannelContact || null === $channelContact->contact) {
            throw new ConversationReplyUndeliverableException(
                "No channel contact linking contact [{$conversation->contact_id}] to channel [{$conversation->channel_id}].",
            );
        }

        return $this->sender->send(
            new OutboundMessage(
                idempotencyKey: 'staff_reply:' . (string) $conversation->getKey() . ':' . Str::ulid(),
                tenantId: (string) $conversation->tenant_id,
                channelId: (string) $channel->getKey(),
                channelType: $channel->type->value,
                transportToken: $channel->token,
                chatId: (string) $channelContact->contact->external_id,
                payload: new MessagePayload(
                    type: 'text',
                    text: $text,
                ),
                // No parse_mode, unlike every other outbound path here. Those
                // carry content an author wrote as markup; this carries prose an
                // operator typed into a chat box. Under parse_mode=HTML a plain
                // "R&D" or "5 < 10" is malformed markup and Telegram rejects the
                // whole message — the operator would watch their reply silently
                // fail to arrive.
                metadata: [
                    'contact_id'   => (string) $conversation->contact_id,
                    'assistant_id' => (string) $conversation->assistant_id,
                    'origin'       => MessageOrigin::Staff->value,
                    'origin_ref'   => ['staff_user_id' => $staffUserId],
                ],
            ),
        );
    }
}
