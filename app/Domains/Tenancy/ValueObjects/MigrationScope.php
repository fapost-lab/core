<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\ValueObjects;

final class MigrationScope
{
    private function __construct(
        public readonly string $path,
        public readonly string $label,
    ) {
    }

    public static function tenant(): self
    {
        return new self(
            path: database_path('migrations/tenant'),
            label: 'tenant',
        );
    }

    public static function features(): self
    {
        return new self(
            path: database_path('migrations/features'),
            label: 'features',
        );
    }

    public static function module(string $name): self
    {
        return new self(
            path: database_path("migrations/modules/{$name}"),
            label: "module:{$name}",
        );
    }
}
