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

    /**
     * Remove every registry entry of a tenant, from the landlord table and the
     * Redis cache. Returns how many were removed.
     */
    public function deleteForTenant(string $tenantId): int;

    /**
     * Record which ingress host this webhook was actually registered against.
     *
     * Called only after the provider has accepted the URL, so the stored value
     * describes reality rather than current configuration. Null clears it, which
     * is the correct state once the provider-side webhook is removed.
     */
    public function recordIngress(string $publicHash, ?string $baseUrl): void;
}
