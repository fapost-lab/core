<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

/**
 * Marker interface for binding clarity — tenants have their own repository
 * instance even though the contract is identical to {@see TranslationOverrideRepositoryInterface}.
 * The container binds this to a concrete implementation backed by the
 * `tenant_translations` table.
 */
interface TenantTranslationRepositoryInterface extends TranslationOverrideRepositoryInterface
{
}
