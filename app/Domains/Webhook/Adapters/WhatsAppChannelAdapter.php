<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Adapters;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Webhook\Contracts\ChannelAdapterInterface;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\OutgoingMessage;
use FAPost\Foundation\DTO\SendResult;
use LogicException;

final class WhatsAppChannelAdapter implements ChannelAdapterInterface
{
    public function platform(): PlatformEnum
    {
        return PlatformEnum::WhatsApp;
    }

    public function verifySignature(array $headers, string $body, string $secret): bool
    {
        throw new LogicException('WhatsApp adapter is not implemented yet.');
    }

    public function parseIncoming(string $body): IncomingMessage
    {
        throw new LogicException('WhatsApp adapter is not implemented yet.');
    }

    public function send(OutgoingMessage $message, string $token): SendResult
    {
        throw new LogicException('WhatsApp adapter is not implemented yet.');
    }
}
