<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

/**
 * Tenant-scoped flavour of {@see TranslationOverrideServiceInterface} —
 * exists as a separate marker so the container can bind it independently of
 * the assistant-scoped service.
 */
interface TenantTranslationServiceInterface extends TranslationOverrideServiceInterface
{
}
