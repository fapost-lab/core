<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

interface WebhookRegistryReaderInterface
{
    /**
     * Find a webhook registry row by its public hash.
     *
     * Returns a plain object with the landlord webhook_registry row columns,
     * or null if no entry exists for the given hash.
     */
    public function findByHash(string $hash): ?object;
}
