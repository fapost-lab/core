<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

use App\Domains\Tenancy\ValueObjects\SupportAccessClaim;
use SensitiveParameter;

/**
 * Consumes a support access token. The other domains reach the landlord token table only through this.
 */
interface SupportAccessRedeemerInterface
{
    /**
     * Consumes the token once. Returns null when it is unknown, expired, already used or was issued for
     * another tenant; those cases are indistinguishable to the caller.
     */
    public function redeem(#[SensitiveParameter] string $plainToken, string $tenantId): ?SupportAccessClaim;
}
