<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Flow\Contracts\InlineKeyboardEditorInterface;
use App\Domains\Flow\Models\FlowSession;
use Fapost\Foundation\Messaging\MessagePayload;
use Fapost\Foundation\Messaging\MessageSenderInterface as OutboundMessageSenderInterface;
use Fapost\Foundation\Messaging\OutboundMessage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Removes the inline keyboard from a previously sent flow message
 * by dispatching a remove_keyboard OutboundMessage through the standard sender pipeline.
 */
final readonly class FlowInlineKeyboardEditor implements InlineKeyboardEditorInterface
{
    public function __construct(
        private OutboundMessageSenderInterface $sender,
    ) {
    }

    public function removeKeyboard(
        string $tenantId,
        string $contactId,
        string $sessionId,
        string $externalMessageId,
    ): void {
        try {
            $session = FlowSession::query()->select(['id', 'assistant_id'])->find($sessionId);

            if (! $session instanceof FlowSession) {
                return;
            }

            $channelContact = ChannelContact::query()
                ->select('channel_contacts.*')
                ->join('channels', 'channels.id', '=', 'channel_contacts.channel_id')
                ->where('channel_contacts.contact_id', $contactId)
                ->where('channels.assistant_id', $session->assistant_id)
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
                    idempotencyKey: 'remove_keyboard:' . $sessionId . ':' . Str::ulid(),
                    tenantId: $tenantId,
                    channelId: (string)$channel->getKey(),
                    channelType: $channel->type->value,
                    transportToken: $channel->token,
                    chatId: (string)$channelContact->contact->external_id,
                    payload: new MessagePayload(type: 'remove_keyboard', text: ''),
                    metadata: ['edit_message_id' => $externalMessageId],
                )
            );
        } catch (Throwable) {
            // Best-effort: never block flow execution on a keyboard edit failure.
        }
    }
}
