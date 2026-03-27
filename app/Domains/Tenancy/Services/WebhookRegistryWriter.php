<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\WebhookRegistryWriterInterface;
use Illuminate\Support\Facades\Redis;

/**
 * Единственное место записи в Redis webhook registry.
 * Assistant Domain вызывает этот сервис — не пишет в Redis напрямую.
 *
 * DB = source of truth. Redis = write-through cache (no TTL; entries must be deleted explicitly).
 */
final class WebhookRegistryWriter implements WebhookRegistryWriterInterface
{
    public function write(
        string $publicHash,
        TenantInterface $tenant,
        string $assistantId,
        string $channelId,
        string $channelType,
        string $secretToken,
    ): void {
        Redis::set(
            $this->key($publicHash),
            json_encode([
                'tenant_id'    => $tenant->getId(),
                'assistant_id' => $assistantId,
                'channel_id'   => $channelId,
                'schema'       => $tenant->getSchemaName(),
                'channel'      => $channelType,
                'secret_token' => $secretToken,
            ], JSON_THROW_ON_ERROR),
        );
    }

    public function delete(string $publicHash): void
    {
        Redis::del($this->key($publicHash));
    }

    private function key(string $publicHash): string
    {
        return 'webhook:' . $publicHash;
    }
}
