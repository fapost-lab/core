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
use Fapost\Foundation\Media\ReportsProviderKind;

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
final readonly class TelegramMediaUploader implements ChannelMediaUploaderInterface, ReportsProviderKind
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

        $found     = $this->extractFileId($result, $blob->getMimeType());
        $messageId = isset($result['message_id']) ? (string)$result['message_id'] : null;

        if (null === $found) {
            throw MediaUploadFailedException::storageFailed(
                'Telegram response did not contain a file_id for the uploaded media.',
            );
        }

        return new UploadResult(
            providerFileId: $found['file_id'],
            expiresAt: null,
            deliveredMessageId: $messageId,
            providerKind: $found['kind'],
        );
    }

    private function filenameFor(MediaBlobReadInterface $blob): string
    {
        return sprintf('%s.bin', $blob->getId());
    }

    /**
     * Pull the file_id of the uploaded asset out of Telegram's response payload.
     *
     * Telegram decides what a file is, not the method that was asked for: a WAV sent through sendAudio may come back
     * as a `document` or a `voice`. So every media field is searched, the requested one first. Photos arrive as an
     * array of size variants; we take the largest.
     *
     * The field it came from is the kind Telegram stored the file as, and the cached id has to be sent by it.
     *
     * @param  array<string, mixed>  $result
     *
     * @return array{file_id: string, kind: string}|null
     */
    private function extractFileId(array $result, string $mimeType): ?array
    {
        $expected = DeliveryKind::payloadType(null, $mimeType);

        $fields = array_values(array_unique([$expected, 'photo', 'video', 'audio', 'voice', 'document']));

        foreach ($fields as $field) {
            $media = $result[$field] ?? null;

            if (! is_array($media) || [] === $media) {
                continue;
            }

            // A photo is a list of size variants, every other media field a single object.
            $file = array_is_list($media) ? end($media) : $media;

            if (is_array($file) && isset($file['file_id']) && is_string($file['file_id']) && '' !== $file['file_id']) {
                return ['file_id' => $file['file_id'], 'kind' => $field];
            }
        }

        return null;
    }
}
