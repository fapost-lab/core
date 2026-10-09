<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Channels\SecretRedactor;
use App\Domains\Channels\Telegram\Dto\SendDocumentDto;
use App\Domains\Channels\Telegram\Dto\SendMessageDto;
use App\Domains\Channels\Telegram\Dto\SendPhotoDto;
use App\Domains\Channels\Telegram\Dto\SendVideoDto;
use App\Domains\Channels\Telegram\Dto\SendVoiceDto;
use App\Domains\Channels\Telegram\Dto\SetWebhookDto;
use App\Domains\Channels\Telegram\Exceptions\TelegramApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Minimal Telegram Bot API client used by the Telegram channel integration.
 */
final readonly class TelegramBotApiClient
{
    /**
     * @param  string  $token  Bot token used to build authenticated Telegram API URLs.
     * @param  string  $apiBaseUrl  Bot API origin; a local stub replaces it in load tests.
     */
    public function __construct(
        private string $token,
        private string $apiBaseUrl = 'https://api.telegram.org',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMessage(SendMessageDto $dto): array
    {
        return $this->request(
            'sendMessage',
            array_filter($dto->toArray(), static fn (mixed $value): bool => null !== $value)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function sendPhoto(SendPhotoDto $dto): array
    {
        return $this->request(
            'sendPhoto',
            array_filter($dto->toArray(), static fn (mixed $value): bool => null !== $value)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function sendDocument(SendDocumentDto $dto): array
    {
        return $this->request(
            'sendDocument',
            array_filter($dto->toArray(), static fn (mixed $value): bool => null !== $value)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function sendVideo(SendVideoDto $dto): array
    {
        return $this->request(
            'sendVideo',
            array_filter($dto->toArray(), static fn (mixed $value): bool => null !== $value)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function sendVoice(SendVoiceDto $dto): array
    {
        return $this->request(
            'sendVoice',
            array_filter($dto->toArray(), static fn (mixed $value): bool => null !== $value)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function setWebhook(SetWebhookDto $dto): array
    {
        return $this->request('setWebhook', array_filter([
            'url'             => $dto->url,
            'secret_token'    => $dto->secretToken,
            'allowed_updates' => $dto->allowedUpdates,
            'max_connections' => $dto->maxConnections,
        ], static fn (mixed $value): bool => null !== $value));
    }

    /**
     * @return array<string, mixed>
     */
    public function getMe(): array
    {
        return $this->request('getMe', []);
    }

    /**
     * Show a chat action (typing, upload_photo, …) to the user.
     *
     * Telegram auto-clears the action after ~5 seconds or when the bot sends
     * the next message — used by the typing indicator service to signal
     * "bot is processing" during long-running flow execution.
     *
     * @return array<string, mixed>
     */
    public function sendChatAction(string $chatId, string $action = 'typing'): array
    {
        return $this->request('sendChatAction', [
            'chat_id' => $chatId,
            'action'  => $action,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteWebhook(): array
    {
        return $this->request('deleteWebhook', []);
    }

    /**
     * Removes the inline keyboard from a previously sent message.
     *
     * @return array<string, mixed>
     */
    public function editMessageReplyMarkup(string $chatId, int $messageId): array
    {
        return $this->request('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => ['inline_keyboard' => []],
        ]);
    }

    /**
     * Send a photo via multipart upload (raw bytes) and return Telegram's response.
     *
     * Used by the media upload-as-send pipeline: Telegram has no separate upload endpoint,
     * so the bytes are delivered to a real chat and the resulting file_id is cached for
     * subsequent sends.
     *
     * @param  resource  $stream
     *
     * @return array<string, mixed>
     */
    public function sendPhotoMultipart(string $chatId, $stream, string $filename, ?string $caption = null): array
    {
        return $this->multipartRequest('sendPhoto', 'photo', $chatId, $stream, $filename, $caption);
    }

    /**
     * Send a document via multipart upload. See {@see sendPhotoMultipart()}.
     *
     * @param  resource  $stream
     *
     * @return array<string, mixed>
     */
    public function sendDocumentMultipart(string $chatId, $stream, string $filename, ?string $caption = null): array
    {
        return $this->multipartRequest('sendDocument', 'document', $chatId, $stream, $filename, $caption);
    }

    /**
     * Send a video via multipart upload. See {@see sendPhotoMultipart()}.
     *
     * @param  resource  $stream
     *
     * @return array<string, mixed>
     */
    public function sendVideoMultipart(string $chatId, $stream, string $filename, ?string $caption = null): array
    {
        return $this->multipartRequest('sendVideo', 'video', $chatId, $stream, $filename, $caption);
    }

    /**
     * Send an audio file via multipart upload. See {@see sendPhotoMultipart()}.
     *
     * @param  resource  $stream
     *
     * @return array<string, mixed>
     */
    public function sendAudioMultipart(string $chatId, $stream, string $filename, ?string $caption = null): array
    {
        return $this->multipartRequest('sendAudio', 'audio', $chatId, $stream, $filename, $caption);
    }

    /**
     * Resolve a Telegram file_id to a downloadable file_path via getFile.
     *
     * @return array<string, mixed>
     */
    public function getFile(string $fileId): array
    {
        return $this->request('getFile', ['file_id' => $fileId]);
    }

    /**
     * Stream a previously resolved Telegram file by its file_path.
     *
     * @return resource
     */
    public function downloadFile(string $filePath)
    {
        try {
            $response = Http::retry(
                3,
                100,
                static function (Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if ($exception instanceof RequestException) {
                        return $exception->response->serverError();
                    }

                    return false;
                },
            )
                ->withOptions(['stream' => true])
                ->get(sprintf('%s/file/bot%s/%s', $this->apiBaseUrl, $this->token, $filePath))
                ->throw();
        } catch (Throwable $exception) {
            throw $this->redacted($exception);
        }

        $stream = $response->toPsrResponse()->getBody()->detach();

        if (null === $stream) {
            throw new TelegramApiException('Telegram file download stream was not available.');
        }

        return $stream;
    }

    /**
     * Execute one Telegram API request with retry semantics and response validation.
     *
     * @param  array<string, mixed>  $payload
     *
     * @return array<string, mixed>
     */
    private function request(string $method, array $payload): array
    {
        try {
            $response = Http::retry(
                3,
                100,
                static function (Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if ($exception instanceof RequestException) {
                        return $exception->response->serverError();
                    }

                    return false;
                }
            )
                ->asJson()
                ->post(
                    "{$this->apiBaseUrl}/bot{$this->token}/{$method}",
                    $payload,
                )
                ->throw();
        } catch (Throwable $exception) {
            throw $this->redacted($exception);
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        if (false === ($data['ok'] ?? false)) {
            $description = is_string($data['description'] ?? null)
                ? $data['description']
                : 'Telegram API request failed.';
            throw new TelegramApiException(SecretRedactor::redact($description, $this->token));
        }

        return $data;
    }

    /**
     * Wraps a transport failure into an exception that carries no bot token.
     *
     * The HTTP stack quotes the request URL (`/bot<token>/method`) in its messages, and the original
     * exception, kept as `previous`, would carry the same text into logs, Sentry and `failed_jobs`.
     * It is therefore not chained: its class and code are named in the message instead.
     */
    private function redacted(Throwable $exception): TelegramApiException
    {
        return new TelegramApiException(
            SecretRedactor::redact(
                sprintf('%s: %s', $exception::class, $exception->getMessage()),
                $this->token,
            ),
            (int)$exception->getCode(),
        );
    }

    /**
     * Issue a multipart Telegram API request that uploads raw file bytes.
     *
     * @param  resource  $stream
     *
     * @return array<string, mixed>
     */
    private function multipartRequest(
        string $method,
        string $fileField,
        string $chatId,
        $stream,
        string $filename,
        ?string $caption,
    ): array {
        $request = Http::retry(
            3,
            100,
            static function (Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                if ($exception instanceof RequestException) {
                    return $exception->response->serverError();
                }

                return false;
            },
        )
            ->attach($fileField, $stream, $filename)
            ->asMultipart();

        try {
            $response = $request
                ->post(
                    sprintf('%s/bot%s/%s', $this->apiBaseUrl, $this->token, $method),
                    array_filter([
                        'chat_id' => $chatId,
                        'caption' => $caption,
                    ], static fn (mixed $value): bool => null !== $value),
                )
                ->throw();
        } catch (Throwable $exception) {
            throw $this->redacted($exception);
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        if (false === ($data['ok'] ?? false)) {
            $description = is_string($data['description'] ?? null)
                ? $data['description']
                : 'Telegram API multipart request failed.';
            throw new TelegramApiException(SecretRedactor::redact($description, $this->token));
        }

        return $data;
    }
}
