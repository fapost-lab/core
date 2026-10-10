<?php

declare(strict_types=1);

namespace App\Domains\Staff\Exceptions;

use DomainException;

/**
 * A change to staff users or roles that a guard refuses for everyone, administrators included: the Gate's admin bypass
 * cannot let it through, so the service says no. The reason is a stable key the caller translates.
 */
final class StaffChangeRefusedException extends DomainException
{
    public const string OWN_ACCOUNT = 'own_account';

    public const string OWN_ROLES = 'own_roles';

    public const string LAST_ADMIN = 'last_admin';

    public const string PLATFORM_SUPPORT = 'platform_support';

    public const string SYSTEM_ROLE = 'system_role';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("Staff change refused: {$reason}.");
    }
}
