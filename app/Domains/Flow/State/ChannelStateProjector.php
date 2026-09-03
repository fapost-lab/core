<?php

declare(strict_types=1);

namespace App\Domains\Flow\State;

use App\Domains\Channels\Models\Channel;

/**
 * Projects the channel a conversation runs on into `system.channel.*`.
 *
 * Flow authors regularly need the bot's own identity — inviting a contact to
 * the bot, printing its handle in a message, building a deep link. Without this
 * projection the only way to get it is an HTTP call node against the provider
 * API, which is a lot of ceremony for a value the platform already stores.
 *
 * Seeded once, when the session is created: sessions are short-lived, so a bot
 * rename surfaces in the next session instead of mutating a running one.
 */
final readonly class ChannelStateProjector
{
    /**
     * State fragment for `system.channel`, or an empty array when no active
     * channel can be resolved (the flow then reads empty strings rather than
     * failing — same graceful behaviour as any other unknown path).
     *
     * @return array<string, mixed>
     */
    public function project(string $contactId, string $assistantId): array
    {
        $channel = $this->resolve($contactId, $assistantId);

        if (! $channel instanceof Channel) {
            return [];
        }

        return [
            'id'   => (string)$channel->getKey(),
            'type' => $channel->type->value,
            // Both spellings: `bot_username` for composing text by hand,
            // `bot_handle` for dropping the ready-to-paste `@name` in.
            'bot_username' => $channel->publicUsername(),
            'bot_handle'   => $channel->publicHandle(),
            'link'         => $channel->publicUrl(),
        ];
    }

    /**
     * Channel the contact most recently interacted with for this assistant —
     * the same "latest interaction wins" rule outbound delivery applies
     * ({@see \App\Domains\Flow\Services\FlowMessageSender}), so the projected
     * identity always matches the bot the reply will come from.
     */
    private function resolve(string $contactId, string $assistantId): ?Channel
    {
        return Channel::query()
            ->select('channels.*')
            ->join('channel_contacts', 'channel_contacts.channel_id', '=', 'channels.id')
            ->where('channel_contacts.contact_id', $contactId)
            ->where('channels.assistant_id', $assistantId)
            ->where('channels.is_active', true)
            ->orderByDesc('channel_contacts.last_interaction_at')
            ->first();
    }
}
