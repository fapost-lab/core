<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Infrastructure;

use App\Domains\Tenancy\Contracts\WebhookRegistryReaderInterface;
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
}
