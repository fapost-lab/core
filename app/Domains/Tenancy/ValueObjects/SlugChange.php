<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\ValueObjects;

use App\Domains\Tenancy\Contracts\TenantInterface;
use Carbon\CarbonImmutable;

/**
 * The outcome of {@see \App\Domains\Tenancy\Services\TenantSlugChanger::change()}.
 */
final readonly class SlugChange
{
    public function __construct(
        /** The tenant carrying the slug it has now. */
        public TenantInterface $tenant,
        public string $previousSlug,
        public bool $changed,
        /** Until when the previous slug redirects; null when nothing changed or redirects are off. */
        public ?CarbonImmutable $redirectUntil,
    ) {
    }
}
