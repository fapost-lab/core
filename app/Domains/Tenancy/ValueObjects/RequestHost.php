<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\ValueObjects;

/**
 * Whose host a request arrived on, as decided by
 * {@see \App\Domains\Tenancy\Services\RequestHostClassifier}.
 */
final readonly class RequestHost
{
    private function __construct(
        public RequestHostKind $kind,
        public ?string $slug = null,
    ) {
    }

    public static function platform(): self
    {
        return new self(RequestHostKind::Platform);
    }

    public static function tenant(string $slug): self
    {
        return new self(RequestHostKind::Tenant, $slug);
    }

    public static function foreign(): self
    {
        return new self(RequestHostKind::Foreign);
    }

    public function isPlatform(): bool
    {
        return RequestHostKind::Platform === $this->kind;
    }

    public function isTenant(): bool
    {
        return RequestHostKind::Tenant === $this->kind;
    }

    public function isForeign(): bool
    {
        return RequestHostKind::Foreign === $this->kind;
    }
}
