<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

interface WebhookRegistryWriterInterface
{
    public function write(
        string $publicHash,
        TenantInterface $tenant,
        string $assistantId,
        string $channelId,
        string $channelType,
        string $secretToken,
    ): void;

    public function delete(string $publicHash): void;
}
