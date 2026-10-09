<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Infrastructure;

use Fapost\Foundation\Tenancy\Exceptions\TenantProvisioningFailedException;
use Illuminate\Hashing\HashManager;

/**
 * Checks the first administrator's e-mail and password hash the way both provisioning contracts promise.
 */
final readonly class FirstAdminCredentialsGuard
{
    public const string DEFAULT_ADMIN_NAME = 'Administrator';

    public function __construct(
        private HashManager $hasher,
    ) {
    }

    /**
     * @throws TenantProvisioningFailedException
     */
    public function assertValid(string $email, string $passwordHash): void
    {
        if ('' === mb_trim($email) || '' === $passwordHash) {
            throw TenantProvisioningFailedException::adminCredentialsMissing();
        }

        // The User model's `hashed` cast stores a hash as-is only when it matches the configured
        // algorithm; anything else would be hashed a second time and the admin could not sign in.
        if (! $this->hasher->isHashed($passwordHash) || ! $this->hasher->verifyConfiguration($passwordHash)) {
            throw TenantProvisioningFailedException::adminPasswordHashInvalid();
        }
    }

    public function nameOrDefault(string $name): string
    {
        return '' === mb_trim($name) ? self::DEFAULT_ADMIN_NAME : $name;
    }
}
