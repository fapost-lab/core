<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\ValueObjects;

/**
 * The operator a redeemed support access token was issued to.
 */
final readonly class SupportAccessClaim
{
    public function __construct(
        public string $operatorRef,
        public string $operatorName,
        public string $operatorEmail,
    ) {
    }
}
