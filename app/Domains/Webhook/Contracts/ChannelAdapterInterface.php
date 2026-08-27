<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Contracts;

use App\Domains\Contact\Enums\PlatformEnum;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\OutgoingMessage;
use Fapost\Foundation\DTO\SendResult;
use Illuminate\Http\Request;

interface ChannelAdapterInterface
{
    public function platform(): PlatformEnum;

    /**
     * Verify the incoming webhook request signature using the pre-resolved secret.
     *
     * Called in ingress before any database access.
     * The secret comes from the Redis webhook registry (write-through cache) — no DB involved.
     */
    public function verifySignature(Request $request, string $secret): bool;

    /**
     * Extract a platform-specific idempotency key from the request body.
     *
     * Called in ingress immediately after signature verification.
     * Must perform minimal parsing — only the fields needed to build the key.
     *
     * @TODO ADR-XX: per-platform format vs hash(raw_body) vs hybrid.
     *               Decided per-adapter until cross-platform strategy is fixed.
     */
    public function extractIdempotencyKey(Request $request, string $channelId): string;

    /**
     * Normalize the raw webhook payload into a platform-agnostic IncomingMessage.
     *
     * Called exclusively in the worker (IncomingMessageJob), never in ingress.
     * Receives the already-decoded payload array that was stored in InboundWebhookPayload.
     *
     * @param  array<string, mixed>  $rawPayload
     */
    public function normalize(array $rawPayload): IncomingMessage;

    public function send(OutgoingMessage $message, string $token): SendResult;
}
