<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Spatie\LaravelSettings\SettingsRepositories\SettingsRepository;

/**
 * Settings repository for tests that stands in for "one schema per tenant": it reads and writes the
 * bucket of the tenant currently set in {@see TenantContextInterface}, so two tenants hold different
 * values without a database.
 */
final class PerTenantSettingsRepository implements SettingsRepository
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        array $config,
        private readonly TenantContextInterface $context,
        private readonly PerTenantSettingsStore $store,
    ) {
    }

    public function getPropertiesInGroup(string $group): array
    {
        return $this->store->read($this->bucket(), $group);
    }

    public function checkIfPropertyExists(string $group, string $name): bool
    {
        return array_key_exists($name, $this->getPropertiesInGroup($group));
    }

    public function getPropertyPayload(string $group, string $name)
    {
        return $this->getPropertiesInGroup($group)[$name] ?? null;
    }

    public function createProperty(string $group, string $name, $payload, bool $locked = false): void
    {
        $this->store->merge($this->bucket(), $group, [$name => $payload]);
    }

    public function updatePropertiesPayload(string $group, array $properties): void
    {
        $this->store->merge($this->bucket(), $group, $properties);
    }

    public function deleteProperty(string $group, string $name): void
    {
        $properties = $this->getPropertiesInGroup($group);
        unset($properties[$name]);

        $this->store->seed($this->bucket(), $group, $properties);
    }

    public function lockProperties(string $group, array $properties): void
    {
    }

    public function unlockProperties(string $group, array $properties): void
    {
    }

    public function getLockedProperties(string $group): array
    {
        return [];
    }

    private function bucket(): string
    {
        return $this->context->isResolved() ? $this->context->get()->getId() : 'landlord';
    }
}
