<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Webhook\Contracts\ChannelAdapterInterface;
use App\Domains\Webhook\Exceptions\InvalidSignatureException;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\OutgoingMessage;
use FAPost\Foundation\DTO\SendResult;
use Illuminate\Http\Request;
use JsonException;

/**
 * Telegram implementation of the inbound webhook adapter contract.
 */
final readonly class TelegramAdapter implements ChannelAdapterInterface
{
    public function __construct(
        private TelegramSignatureVerifier $signatureVerifier,
        private TelegramInboundNormalizer $normalizer,
    ) {
    }

    /**
     * Return the external platform handled by this adapter.
     */
    public function platform(): PlatformEnum
    {
        return PlatformEnum::Telegram;
    }

    /**
     * Validate the Telegram webhook secret header.
     */
    public function verifySignature(array $headers, string $body, string $secret): bool
    {
        $headerToken = $headers['x-telegram-bot-api-secret-token'] ?? '';

        if (is_array($headerToken)) {
            $headerToken = $headerToken[0] ?? '';
        }

        return $this->signatureVerifier->verify((string) $headerToken, $secret);
    }

    /**
     * Parse and normalize the raw Telegram webhook body.
     *
     * @throws JsonException
     */
    public function parseIncoming(string $body): IncomingMessage
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        return $this->normalizer->normalize($payload);
    }

    /**
     * Sending is handled by the outbound messaging pipeline, not by webhook adapters.
     */
    public function send(OutgoingMessage $message, string $token): SendResult
    {
        return SendResult::fail('TelegramAdapter::send is not used in messaging pipeline.');
    }

    /**
     * @param  Request     $request
     * @param  array{secret_token: string}  $registryPayload
     *
     * @return IncomingMessage
     */
    public function handle(Request $request, array $registryPayload): IncomingMessage
    {
        $secretToken = (string) ($registryPayload['secret_token'] ?? '');
        $headerToken = (string) $request->header('x-telegram-bot-api-secret-token', '');

        if ( ! $this->signatureVerifier->verify($headerToken, $secretToken)) {
            throw new InvalidSignatureException('Invalid Telegram webhook signature.');
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        return $this->normalizer->normalize($payload);
    }
}
