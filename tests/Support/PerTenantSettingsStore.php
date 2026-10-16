<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Settings payloads kept in memory, one bucket per tenant (or `landlord` when none is resolved),
 * shared by every {@see PerTenantSettingsRepository} of a test.
 */
final class PerTenantSettingsStore
{
    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $buckets = [];

    /**
     * @param  array<string, mixed>  $payloads
     */
    public function seed(string $bucket, string $group, array $payloads): void
    {
        $this->buckets[$bucket][$group] = $payloads;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(string $bucket, string $group): array
    {
        return $this->buckets[$bucket][$group] ?? [];
    }

    /**
     * @param  array<string, mixed>  $payloads
     */
    public function merge(string $bucket, string $group, array $payloads): void
    {
        $this->buckets[$bucket][$group] = [...$this->read($bucket, $group), ...$payloads];
    }
}
