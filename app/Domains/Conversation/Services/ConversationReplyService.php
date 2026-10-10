<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Services;

use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Conversation\Capture\ConversationCaptureFactory;
use App\Domains\Conversation\Contracts\ConversationLoggerInterface;
use App\Domains\Conversation\Contracts\ConversationReplyServiceInterface;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Conversation\Exceptions\ConversationReplyUndeliverableException;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Media\Contracts\MediaDispatcherInterface;
use App\Domains\Media\Exceptions\MediaDeletedException;
use App\Domains\Media\Exceptions\MediaNotFoundException;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Messaging\OutboundVolumeGate;
use Fapost\Foundation\Media\DTO\UploadContext;
use Fapost\Foundation\Media\Enums\MediaKind;
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
        private MediaDispatcherInterface $mediaDispatcher,
        private ConversationLoggerInterface $conversationLogger,
        private ConversationCaptureFactory $captureFactory,
        private OutboundVolumeGate $volumeGate,
    ) {
    }

    public function send(
        Conversation $conversation,
        string $text,
        string $staffUserId,
        ?string $mediaFileId = null,
    ): DeliveryResult {
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

        $chatId = (string) $channelContact->contact->external_id;

        if (null === $mediaFileId) {
            return $this->sender->send($this->buildMessage($conversation, $channel, $chatId, new MessagePayload(
                type: 'text',
                text: $text,
            ), $staffUserId));
        }

        return $this->sendMedia($conversation, $channel, $chatId, $text, $staffUserId, $mediaFileId);
    }

    /**
     * Attachment path: the file has to exist provider-side before it can be
     * referenced in a message, so it goes through the dispatcher first.
     *
     * Telegram uploads as it sends — the dispatcher reports `alreadyDelivered`
     * and the recipient already has the file, so the send is skipped and the
     * transcript captured here instead of by MessageSender.
     */
    private function sendMedia(
        Conversation $conversation,
        Channel $channel,
        string $chatId,
        string $caption,
        string $staffUserId,
        string $mediaFileId,
    ): DeliveryResult {
        $media = MediaFile::query()
            ->withTrashed()
            ->where('tenant_id', $conversation->tenant_id)
            ->whereKey($mediaFileId)
            ->first();

        if (! $media instanceof MediaFile) {
            throw MediaNotFoundException::forId($mediaFileId);
        }

        if (null !== $media->deleted_at) {
            throw MediaDeletedException::forId($mediaFileId);
        }

        // The key is fixed before the upload, which may deliver the message itself and never reach
        // MessageSender; the message carries the same key, so the operator counts the unit once.
        $idempotencyKey = $this->newIdempotencyKey($conversation);

        $this->volumeGate->admitKey($idempotencyKey);

        $dispatch = $this->mediaDispatcher->ensureUploadedToChannel(
            media: $media,
            channel: $channel,
            context: new UploadContext(targetChatId: $chatId, caption: '' !== $caption ? $caption : null),
        );

        $kind    = MediaKind::fromMimeType((string) ($media->blob->mime_type ?? ''));
        $message = $this->buildMessage(
            $conversation,
            $channel,
            $chatId,
            $this->mediaPayload($kind, (string) $dispatch->providerFileId, $caption),
            $staffUserId,
            $idempotencyKey,
        );

        if ($dispatch->alreadyDelivered) {
            $result = new DeliveryResult(sent: true, providerMessageId: $dispatch->deliveredMessageId);

            $entry = $this->captureFactory->forOutbound($message, $result);

            if (null !== $entry) {
                $this->conversationLogger->log($entry);
            }

            return $result;
        }

        return $this->sender->send($message);
    }

    private function mediaPayload(MediaKind $kind, string $providerFileId, string $caption): MessagePayload
    {
        return match ($kind) {
            MediaKind::Image => new MessagePayload(type: 'photo', text: $caption, media: ['photo' => $providerFileId]),
            MediaKind::Video => new MessagePayload(type: 'video', text: $caption, media: ['video' => $providerFileId]),
            MediaKind::Audio => new MessagePayload(type: 'voice', text: '', media: ['voice' => $providerFileId]),
            default          => new MessagePayload(type: 'document', text: $caption, media: ['document' => $providerFileId]),
        };
    }

    private function newIdempotencyKey(Conversation $conversation): string
    {
        return 'staff_reply:' . (string) $conversation->getKey() . ':' . Str::ulid();
    }

    private function buildMessage(
        Conversation $conversation,
        Channel $channel,
        string $chatId,
        MessagePayload $payload,
        string $staffUserId,
        ?string $idempotencyKey = null,
    ): OutboundMessage {
        return new OutboundMessage(
            idempotencyKey: $idempotencyKey ?? $this->newIdempotencyKey($conversation),
            tenantId: (string) $conversation->tenant_id,
            channelId: (string) $channel->getKey(),
            channelType: $channel->type->value,
            transportToken: $channel->token,
            chatId: $chatId,
            payload: $payload,
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
        );
    }
}
