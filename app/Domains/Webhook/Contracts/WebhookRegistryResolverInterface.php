<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Contracts;

use App\Domains\Webhook\DTOs\WebhookRegistryEntry;
use App\Domains\Webhook\Exceptions\WebhookRegistryException;

/**
 * Resolves webhook routing entries with Redis as hot cache and landlord DB as fallback.
 *
 * On Redis miss: queries landlord.webhook_registry (no schema switching required),
 * self-heals Redis, and returns the entry. Concurrent misses for the same hash are
 * serialised via a short-lived Redis lock to prevent thundering-herd DB load.
 */
interface WebhookRegistryResolverInterface
{
    /**
     * Resolve a webhook routing entry by its public hash.
     *
     * @throws WebhookRegistryException When hash is not found in Redis or landlord DB.
     */
    public function resolve(string $hash): WebhookRegistryEntry;
}
