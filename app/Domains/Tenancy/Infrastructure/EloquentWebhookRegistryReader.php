<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Infrastructure;

use App\Domains\Tenancy\Contracts\WebhookRegistryReaderInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads webhook registry entries from the landlord database.
 *
 * This is the only place in the platform allowed to read from landlord.webhook_registry
 * outside of WebhookRegistryWriter. All other domains must depend on the interface.
 */
final class EloquentWebhookRegistryReader implements WebhookRegistryReaderInterface
{
    public function findByHash(string $hash): ?object
    {
        return DB::connection('landlord')
            ->table('webhook_registry')
            ->where('webhook_public_hash', $hash)
            ->first();
    }

    public function countIngressDrift(string $platform, string $expectedBaseUrl): int
    {
        return $this->driftQuery($platform, $expectedBaseUrl)->count();
    }

    /**
     * {@inheritDoc}
     */
    public function findIngressDrift(string $platform, string $expectedBaseUrl, int $limit): array
    {
        return $this->driftQuery($platform, $expectedBaseUrl)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Rows whose recorded ingress host is absent or differs from the expected one.
     */
    private function driftQuery(string $platform, string $expectedBaseUrl): Builder
    {
        return DB::connection('landlord')
            ->table('webhook_registry')
            ->where('platform', $platform)
            ->where(static function ($query) use ($expectedBaseUrl): void {
                $query->whereNull('ingress_base_url')
                    ->orWhere('ingress_base_url', '<>', $expectedBaseUrl);
            });
    }
}
