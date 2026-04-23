<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Webhook\Contracts\ChannelAdapterInterface;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\OutgoingMessage;
use FAPost\Foundation\DTO\SendResult;
use Illuminate\Http\Request;

/**
 * Telegram implementation of the inbound webhook adapter contract.
 *
 * Implements two distinct responsibilities:
 *   - Ingress methods (verifySignature, extractIdempotencyKey): minimal, stateless, no DB.
 *   - Worker method (normalize): full payload parsing via TelegramInboundNormalizer.
 */
final readonly class TelegramAdapter implements ChannelAdapterInterface
{
    private const string SECRET_HEADER = 'x-telegram-bot-api-secret-token';

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
     * Verify the Telegram secret token header against the pre-resolved secret from Redis registry.
     */
    public function verifySignature(Request $request, string $secret): bool
    {
        $headerToken = (string) $request->header(self::SECRET_HEADER, '');

        return $this->signatureVerifier->verify($headerToken, $secret);
    }

    /**
     * Build the Telegram idempotency key from channel ID and update_id.
     *
     * Parses only update_id — the minimum needed for deduplication.
     * Full normalization happens in the worker.
     *
     * Format: tg:{channelId}:{update_id}
     *
     * @TODO ADR-XX: per-platform format vs hash(raw_body) vs hybrid.
     *               Decided per-adapter until cross-platform strategy is fixed.
     */
    public function extractIdempotencyKey(Request $request, string $channelId): string
    {
        $updateId = (string) ($request->json('update_id') ?? '');

        return "tg:{$channelId}:{$updateId}";
    }

    /**
     * Normalize the decoded Telegram webhook payload into a platform-agnostic IncomingMessage.
     *
     * Called only from IncomingMessageJob (worker), never from ingress.
     *
     * @param  array<string, mixed>  $rawPayload
     */
    public function normalize(array $rawPayload): IncomingMessage
    {
        return $this->normalizer->normalize($rawPayload);
    }

    /**
     * Sending is handled by the outbound messaging pipeline, not by webhook adapters.
     */
    public function send(OutgoingMessage $message, string $token): SendResult
    {
        return SendResult::fail('TelegramAdapter::send is not used in messaging pipeline.');
    }
}
