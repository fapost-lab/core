<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\ValueObjects;

use App\Domains\Tenancy\Contracts\TenantInterface;

final readonly class RuntimeTenant implements TenantInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private string $id,
        private string $schemaName,
        private string $slug = 'runtime',
        private bool $active = true,
        private array $config = [],
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getSchemaName(): string
    {
        return $this->schemaName;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }
}
