<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram\Media;

use App\Domains\Channels\Telegram\TelegramBotApiClientFactory;
use App\Domains\Media\Exceptions\MediaIngestException;
use FAPost\Foundation\Channel\ChannelInterface;
use FAPost\Foundation\Media\ChannelMediaDownloaderInterface;
use FAPost\Foundation\Media\DTO\DownloadResult;
use GuzzleHttp\Psr7\Utils;
use Throwable;

/**
 * Telegram-side media downloader used by the input-node ingest pipeline.
 *
 * Resolves a `file_id` to a `file_path` via Telegram's getFile, then streams the bytes
 * from `https://api.telegram.org/file/bot<token>/<file_path>`. Telegram's `file_path`
 * URLs are valid for ~1 hour, but the ingestor consumes the stream immediately so the
 * TTL is effectively a non-issue.
 */
final readonly class TelegramMediaDownloader implements ChannelMediaDownloaderInterface
{
    public function __construct(
        private TelegramBotApiClientFactory $clientFactory,
    ) {
    }

    public function channelType(): string
    {
        return 'telegram';
    }

    public function download(ChannelInterface $channel, string $providerFileId): DownloadResult
    {
        $config = $channel->getConfig();
        $token  = is_string($config['token'] ?? null) ? $config['token'] : '';
        $client = $this->clientFactory->make($token);

        try {
            $response = $client->getFile($providerFileId);
        } catch (Throwable $exception) {
            throw MediaIngestException::downloadFailed($providerFileId, $exception);
        }

        $result   = is_array($response['result'] ?? null) ? $response['result'] : [];
        $filePath = is_string($result['file_path'] ?? null) ? $result['file_path'] : null;
        $size     = isset($result['file_size']) ? (int)$result['file_size'] : 0;

        if (null === $filePath) {
            throw MediaIngestException::downloadFailed($providerFileId);
        }

        try {
            $resource = $client->downloadFile($filePath);
        } catch (Throwable $exception) {
            throw MediaIngestException::downloadFailed($providerFileId, $exception);
        }

        return new DownloadResult(
            stream: Utils::streamFor($resource),
            mimeType: $this->guessMimeFromPath($filePath),
            size: $size,
            originalFilename: basename($filePath),
            expiresAt: null,
        );
    }

    private function guessMimeFromPath(string $filePath): string
    {
        $extension = mb_strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'mp4'         => 'video/mp4',
            'mov'         => 'video/quicktime',
            'mp3'         => 'audio/mpeg',
            'ogg', 'oga'  => 'audio/ogg',
            'wav'         => 'audio/wav',
            'pdf'         => 'application/pdf',
            'zip'         => 'application/zip',
            'json'        => 'application/json',
            'doc'         => 'application/msword',
            'docx'        => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'         => 'application/vnd.ms-excel',
            'xlsx'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt'         => 'text/plain',
            'csv'         => 'text/csv',
            default       => 'application/octet-stream',
        };
    }
}
