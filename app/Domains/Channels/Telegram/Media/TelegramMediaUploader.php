<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram\Media;

use App\Domains\Channels\Telegram\TelegramBotApiClientFactory;
use App\Domains\Media\Exceptions\MediaUploadFailedException;
use App\Domains\Media\Services\DeliveryKind;
use Fapost\Foundation\Channel\ChannelInterface;
use Fapost\Foundation\Media\ChannelMediaUploaderInterface;
use Fapost\Foundation\Media\DTO\UploadContext;
use Fapost\Foundation\Media\DTO\UploadResult;
use Fapost\Foundation\Media\Enums\MediaKind;
use Fapost\Foundation\Media\MediaBlobReadInterface;

/**
 * Telegram-side media uploader using the upload-as-send pattern.
 *
 * Telegram has no separate upload endpoint — file_id is only minted as a side effect of
 * a real send. The first call ships the bytes to the target chat (taken from
 * {@see UploadContext::$targetChatId}); subsequent sends short-circuit through the
 * cached file_id in {@see \App\Domains\Media\Models\MediaChannelRef}.
 *
 * The method follows {@see DeliveryKind}: a format the typed method refuses (HEIC, MKV, FLAC…) goes as a document.
 *
 * The resulting `deliveredMessageId` lets the dispatcher signal "already delivered" so
 * upstream handlers do not double-send to the same recipient.
 */
final readonly class TelegramMediaUploader implements ChannelMediaUploaderInterface
{
    public function __construct(
        private TelegramBotApiClientFactory $clientFactory,
    ) {
    }

    public function channelType(): string
    {
        return 'telegram';
    }

    public function upload(
        MediaBlobReadInterface $blob,
        ChannelInterface $channel,
        ?UploadContext $context = null,
    ): UploadResult {
        $chatId = $context?->targetChatId;

        if (null === $chatId || '' === $chatId) {
            throw MediaUploadFailedException::storageFailed(
                'Telegram media uploader requires UploadContext.targetChatId (upload-as-send).',
            );
        }

        $config = $channel->getConfig();
        $token  = is_string($config['token'] ?? null) ? $config['token'] : '';
        $client = $this->clientFactory->make($token);

        $stream   = $blob->openStream();
        $resource = $stream->detach();

        if (null === $resource) {
            throw MediaUploadFailedException::storageFailed(
                'Telegram media uploader could not detach blob stream resource.',
            );
        }

        try {
            $response = match (DeliveryKind::of($blob->getMimeType())) {
                MediaKind::Image => $client->sendPhotoMultipart(
                    $chatId,
                    $resource,
                    $this->filenameFor($blob),
                    $context?->caption
                ),
                MediaKind::Video => $client->sendVideoMultipart(
                    $chatId,
                    $resource,
                    $this->filenameFor($blob),
                    $context?->caption
                ),
                MediaKind::Audio => $client->sendAudioMultipart(
                    $chatId,
                    $resource,
                    $this->filenameFor($blob),
                    $context?->caption
                ),
                MediaKind::Document,
                MediaKind::Sticker,
                MediaKind::Other => $client->sendDocumentMultipart(
                    $chatId,
                    $resource,
                    $this->filenameFor($blob),
                    $context?->caption
                ),
            };
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }

        $result = is_array($response['result'] ?? null) ? $response['result'] : [];

        $providerFileId = $this->extractFileId($result, $blob->getMimeType());
        $messageId      = isset($result['message_id']) ? (string)$result['message_id'] : null;

        if (null === $providerFileId) {
            throw MediaUploadFailedException::storageFailed(
                'Telegram response did not contain a file_id for the uploaded media.',
            );
        }

        return new UploadResult(
            providerFileId: $providerFileId,
            expiresAt: null,
            deliveredMessageId: $messageId,
        );
    }

    private function filenameFor(MediaBlobReadInterface $blob): string
    {
        return sprintf('%s.bin', $blob->getId());
    }

    /**
     * Pull the file_id of the uploaded asset out of Telegram's response payload.
     *
     * Photos arrive as an array of size variants; we take the largest. Other media kinds
     * carry a single object keyed by the media field name.
     *
     * @param  array<string, mixed>  $result
     */
    private function extractFileId(array $result, string $mimeType): ?string
    {
        $kind = DeliveryKind::of($mimeType);

        if (MediaKind::Image === $kind && isset($result['photo']) && is_array($result['photo'])) {
            $largest = end($result['photo']);

            return is_array($largest) && isset($largest['file_id']) ? (string)$largest['file_id'] : null;
        }

        $field = match ($kind) {
            MediaKind::Video => 'video',
            MediaKind::Audio => 'audio',
            default          => 'document',
        };

        if (isset($result[$field]) && is_array($result[$field]) && isset($result[$field]['file_id'])) {
            return (string)$result[$field]['file_id'];
        }

        return null;
    }
}
