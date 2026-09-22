<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\WebhookRegistryWriterInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use JsonException;

/**
 * Единственное место записи в Redis webhook registry и landlord.webhook_registry.
 * Assistant Domain вызывает этот сервис — не пишет в Redis или landlord напрямую.
 *
 * Landlord DB = source of truth. Redis = write-through cache (no TTL; entries must be deleted explicitly).
 * Fallback: при Redis miss резолвер читает из landlord и самовосстанавливает Redis.
 */
final class WebhookRegistryWriter implements WebhookRegistryWriterInterface
{
    /**
     * @throws JsonException
     */
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

        DB::connection('landlord')->table('webhook_registry')->upsert(
            [
                [
                    'webhook_public_hash' => $publicHash,
                    'tenant_id'           => $tenant->getId(),
                    'assistant_id'        => $assistantId,
                    'channel_id'          => $channelId,
                    'schema'              => $tenant->getSchemaName(),
                    'platform'            => $channelType,
                    'secret_token'        => $secretToken,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ],
            ],
            ['webhook_public_hash'],
            ['tenant_id', 'assistant_id', 'channel_id', 'schema', 'platform', 'secret_token', 'updated_at'],
        );
    }

    /**
     * Landlord-only: the ingress host is operational metadata for migration
     * tracking, never consulted on the request path, so it stays out of the
     * Redis entry that ingress reads on every webhook.
     */
    public function recordIngress(string $publicHash, ?string $baseUrl): void
    {
        DB::connection('landlord')->table('webhook_registry')
            ->where('webhook_public_hash', $publicHash)
            ->update([
                'ingress_base_url' => $baseUrl,
                'updated_at'       => now(),
            ]);
    }

    public function delete(string $publicHash): void
    {
        Redis::del($this->key($publicHash));

        DB::connection('landlord')->table('webhook_registry')
            ->where('webhook_public_hash', $publicHash)
            ->delete();
    }

    public function deleteForTenant(string $tenantId): int
    {
        $hashes = DB::connection('landlord')->table('webhook_registry')
            ->where('tenant_id', $tenantId)
            ->pluck('webhook_public_hash');

        foreach ($hashes as $hash) {
            $this->delete((string) $hash);
        }

        return $hashes->count();
    }

    private function key(string $publicHash): string
    {
        return 'webhook:' . $publicHash;
    }
}
