<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Contracts;

use App\Domains\Webhook\DTOs\IncomingMessage;
use Illuminate\Http\Request;

interface ChannelAdapterInterface
{
    public function verifySignature(Request $request, string $secretToken): void;

    public function parse(Request $request): IncomingMessage;
}
