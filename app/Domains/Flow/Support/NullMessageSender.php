<?php

declare(strict_types=1);

namespace App\Domains\Flow\Support;

use App\Domains\Flow\Contracts\MessageSenderInterface;
use RuntimeException;

final class NullMessageSender implements MessageSenderInterface
{
    public function send(string $tenantId, string $contactId, string $sessionId, array $payload): string
    {
        throw new RuntimeException('Message sender is not configured.');
    }
}
