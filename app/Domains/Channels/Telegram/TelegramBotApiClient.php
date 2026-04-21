<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\Dto\SendDocumentDto;
use App\Domains\Channels\Telegram\Dto\SendMessageDto;
use App\Domains\Channels\Telegram\Dto\SendPhotoDto;
use App\Domains\Channels\Telegram\Dto\SetWebhookDto;
use App\Domains\Channels\Telegram\Exceptions\TelegramApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Minimal Telegram Bot API client used by the Telegram channel integration.
 */
final class TelegramBotApiClient
{
    /**
     * @param  string  $token  Bot token used to build authenticated Telegram API URLs.
     */
    public function __construct(
        private readonly string $token,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMessage(SendMessageDto $dto): array
    {
        return $this->request('sendMessage', $dto->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    public function sendPhoto(SendPhotoDto $dto): array
    {
        return $this->request('sendPhoto', $dto->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    public function sendDocument(SendDocumentDto $dto): array
    {
        return $this->request('sendDocument', $dto->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    public function setWebhook(SetWebhookDto $dto): array
    {
        return $this->request('setWebhook', $dto->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteWebhook(): array
    {
        return $this->request('deleteWebhook', []);
    }

    /**
     * Execute one Telegram API request with retry semantics and response validation.
     *
     * @param  array<string, mixed>  $payload
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
            )->asJson()->post(
                "https://api.telegram.org/bot{$this->token}/{$method}",
                $payload,
            )->throw();
        } catch (Throwable $exception) {
            throw new TelegramApiException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        if (false === ($data['ok'] ?? false)) {
            $description = is_string($data['description'] ?? null)
                ? $data['description']
                : 'Telegram API request failed.';
            throw new TelegramApiException($description);
        }

        return $data;
    }
}
