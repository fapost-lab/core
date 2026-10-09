<?php

declare(strict_types=1);

namespace App\Domains\Staff\Exceptions;

use LogicException;

/**
 * The tenant already has a user other than the first administrator being created. Repeating the
 * call cannot help; someone must look at the tenant.
 */
final class FirstAdminConflictException extends LogicException
{
    public static function tenantHasUsers(): self
    {
        return new self('The first admin can be created only in a tenant without users.');
    }
}
