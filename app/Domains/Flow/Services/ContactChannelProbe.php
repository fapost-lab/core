<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Conversation\DTO\ConversationRef;

/**
 * The thread a message from an assistant to a contact would go to: the contact's channel on an **active** channel of
 * that assistant, the most recently used first — the same pick {@see FlowMessageSender} makes when it sends. Null when
 * the contact has no such channel, so the message could not be delivered at all.
 */
final readonly class ContactChannelProbe
{
    public function deliveryThread(string $tenantId, string $contactId, string $assistantId): ?ConversationRef
    {
        $row = ChannelContact::query()
            ->toBase()
            ->select(['channels.id as channel_id', 'channels.type as channel_type'])
            ->join('channels', 'channels.id', '=', 'channel_contacts.channel_id')
            ->where('channel_contacts.contact_id', $contactId)
            ->where('channels.assistant_id', $assistantId)
            ->where('channels.is_active', true)
            ->orderByDesc('channel_contacts.last_interaction_at')
            ->first();

        if (null === $row) {
            return null;
        }

        return new ConversationRef(
            tenantId: $tenantId,
            assistantId: $assistantId,
            contactId: $contactId,
            channelId: (string) $row->channel_id,
            platform: (string) $row->channel_type,
        );
    }
}
