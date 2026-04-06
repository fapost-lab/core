<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Adapters;

use App\Domains\Webhook\Contracts\ChannelAdapterInterface;
use App\Domains\Webhook\DTOs\IncomingMessage;
use Illuminate\Http\Request;
use LogicException;

final class WhatsAppChannelAdapter implements ChannelAdapterInterface
{
    public function verifySignature(Request $request, string $secretToken): void
    {
        throw new LogicException('WhatsApp adapter is not implemented yet.');
    }

    public function parse(Request $request): IncomingMessage
    {
        throw new LogicException('WhatsApp adapter is not implemented yet.');
    }
}
