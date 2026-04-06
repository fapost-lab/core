<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Adapters;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Webhook\Contracts\ChannelAdapterInterface;
use App\Domains\Webhook\DTOs\IncomingMessage;
use App\Domains\Webhook\Exceptions\InvalidSignatureException;
use Illuminate\Http\Request;

final class TelegramChannelAdapter implements ChannelAdapterInterface
{
    private const string HEADER = 'x-telegram-bot-api-secret-token';

    public function verifySignature(Request $request, string $secretToken): void
    {
        $provided = $request->header(self::HEADER, '');

        if ( ! hash_equals($secretToken, $provided)) {
            throw new InvalidSignatureException('Invalid Telegram secret token.');
        }
    }

    public function parse(Request $request): IncomingMessage
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

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
            externalChatId: (string) ($message['chat']['id'] ?? ''),
            externalUserId: (string) ($from['id'] ?? ''),
            text: is_string($callbackData) ? $callbackData : ($message['text'] ?? null),
            platform: PlatformEnum::Telegram,
            messageType: $this->resolveMessageType($payload),
            timestamp: (int) ($message['date'] ?? time()),
            meta: array_filter([
                'username'   => $from['username'] ?? null,
                'first_name' => $from['first_name'] ?? null,
                'last_name'  => $from['last_name'] ?? null,
            ], static fn (mixed $value): bool => null !== $value),
        );
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
            isset($payload['message']['sticker'])  => 'sticker',
            isset($payload['message']['text'])     => 'text',
            default                                => 'unknown',
        };
    }
}
