<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

interface MessageSenderInterface
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(string $tenantId, string $contactId, string $sessionId, array $payload): string;
}
