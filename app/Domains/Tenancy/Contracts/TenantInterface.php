<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

interface TenantInterface
{
    public function getId(): string;

    public function getSlug(): string;

    public function getSchemaName(): string;

    public function isActive(): bool;

    public function getConfig(string $key, mixed $default = null): mixed;
}
