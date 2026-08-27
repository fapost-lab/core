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

    /**
     * Count channels of a platform whose recorded ingress host differs from the expected one.
     *
     * A NULL recorded host counts as drift: it means no registration has confirmed
     * where the provider is actually delivering, so it cannot be assumed correct.
     */
    public function countIngressDrift(string $platform, string $expectedBaseUrl): int;

    /**
     * Fetch a bounded batch of drifted rows, oldest first.
     *
     * Bounded because re-registration calls the provider once per channel and
     * providers rate-limit that call; migration proceeds in batches over time.
     *
     * @return list<object>
     */
    public function findIngressDrift(string $platform, string $expectedBaseUrl, int $limit): array;
}
