<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantInterface;
use Illuminate\Support\Facades\Redis;

/**
 * Единственное место записи в Redis webhook registry.
 * Bot Domain вызывает этот сервис — не пишет в Redis напрямую.
 *
 * DB = source of truth. Redis = write-through cache.
 * При рассинхронизации: бот не отвечает, но данные не теряются.
 */
final class WebhookRegistryWriter
{
    public function write(
        string $publicHash,
        TenantInterface $tenant,
        string $botId,
        string $channel,
        string $secretToken,
    ): void {
        Redis::set(
            $this->key($publicHash),
            json_encode([
                'tenant_id'    => $tenant->getId(),
                'bot_id'       => $botId,
                'schema'       => $tenant->getSchemaName(),
                'channel'      => $channel,
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
