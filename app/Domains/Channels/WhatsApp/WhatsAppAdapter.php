<?php

declare(strict_types=1);

namespace App\Domains\Channels\WhatsApp;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Webhook\Contracts\ChannelAdapterInterface;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\OutgoingMessage;
use Fapost\Foundation\DTO\SendResult;
use Illuminate\Http\Request;
use LogicException;

final class WhatsAppAdapter implements ChannelAdapterInterface
{
    public function platform(): PlatformEnum
    {
        return PlatformEnum::WhatsApp;
    }

    public function verifySignature(Request $request, string $secret): bool
    {
        throw new LogicException('WhatsApp adapter is not implemented yet.');
    }

    public function extractIdempotencyKey(Request $request, string $channelId): string
    {
        throw new LogicException('WhatsApp adapter is not implemented yet.');
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function normalize(array $rawPayload): IncomingMessage
    {
        throw new LogicException('WhatsApp adapter is not implemented yet.');
    }

    public function send(OutgoingMessage $message, string $token): SendResult
    {
        throw new LogicException('WhatsApp adapter is not implemented yet.');
    }
}
