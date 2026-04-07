<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Contracts;

use App\Domains\Contact\Enums\PlatformEnum;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\OutgoingMessage;
use FAPost\Foundation\DTO\SendResult;

interface ChannelAdapterInterface
{
    public function platform(): PlatformEnum;

    /**
     * Verify incoming webhook request signature.
     * Called before any database access, using only headers and request body.
     *
     * @param  array<string, string|string[]>  $headers
     */
    public function verifySignature(array $headers, string $body, string $secret): bool;

    /**
     * Parse webhook body into normalized IncomingMessage.
     * Called only after successful signature verification.
     */
    public function parseIncoming(string $body): IncomingMessage;

    public function send(OutgoingMessage $message, string $token): SendResult;
}
