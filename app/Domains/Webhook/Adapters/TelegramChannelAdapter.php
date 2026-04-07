<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Adapters;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Webhook\Contracts\ChannelAdapterInterface;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\IncomingMessageType;
use FAPost\Foundation\DTO\OutgoingMessage;
use FAPost\Foundation\DTO\SendResult;
use LogicException;

final class TelegramChannelAdapter implements ChannelAdapterInterface
{
    private const string HEADER = 'x-telegram-bot-api-secret-token';

    public function platform(): PlatformEnum
    {
        return PlatformEnum::Telegram;
    }

    public function verifySignature(array $headers, string $body, string $secret): bool
    {
        $provided = $headers[self::HEADER] ?? '';

        if (is_array($provided)) {
            $provided = $provided[0] ?? '';
        }

        return hash_equals($secret, (string) $provided);
    }

    public function parseIncoming(string $body): IncomingMessage
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $from */
        $from = $payload['callback_query']['from']
            ?? $payload['message']['from']
            ?? [];

        /** @var array<string, mixed> $message */
        $message = $payload['message']
            ?? $payload['callback_query']['message']
            ?? [];

        $callbackData = $payload['callback_query']['data'] ?? null;

        return new IncomingMessage(
            updateId: (string) ($payload['update_id'] ?? ''),
            externalUserId: (string) ($from['id'] ?? ''),
            externalChatId: (string) ($message['chat']['id'] ?? ''),
            text: is_string($callbackData) ? $callbackData : ($message['text'] ?? null),
            type: IncomingMessageType::from($this->resolveMessageType($payload)),
            platform: $this->platform()->value,
            payload: array_filter([
                'username'   => $from['username'] ?? null,
                'first_name' => $from['first_name'] ?? null,
                'last_name'  => $from['last_name'] ?? null,
                'timestamp'  => (int) ($message['date'] ?? 0) ?: null,
            ], static fn (mixed $value): bool => null !== $value),
        );
    }

    public function send(OutgoingMessage $message, string $token): SendResult
    {
        throw new LogicException('TelegramChannelAdapter::send() is not implemented yet.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveMessageType(array $payload): string
    {
        return match (true) {
            isset($payload['callback_query'])      => 'callback_query',
            isset($payload['message']['photo'])    => 'photo',
            isset($payload['message']['document']) => 'document',
            isset($payload['message']['voice'])    => 'voice',
            isset($payload['message']['text'])     => 'text',
            default                                => 'unknown',
        };
    }
}
