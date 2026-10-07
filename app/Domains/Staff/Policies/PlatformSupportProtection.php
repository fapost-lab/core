<?php

declare(strict_types=1);

namespace App\Domains\Staff\Policies;

use App\Domains\Staff\Models\User;

/**
 * What nobody, an administrator included, may do to the tenant's platform support user.
 *
 * Administrators pass every policy through `Gate::before`, so a policy cannot protect this account:
 * the Gate calls {@see forbids()} first and a `false` there ends the check.
 */
final class PlatformSupportProtection
{
    /**
     * Abilities that change or remove an account.
     *
     * @var list<string>
     */
    public const array PROTECTED_ABILITIES = [
        'update',
        'updateProfile',
        'updateRoles',
        'delete',
        'forceDelete',
        'restore',
        'deactivate',
        'activate',
    ];

    /**
     * @param  array<array-key, mixed>  $arguments  as the Gate passes them; the target user comes first
     */
    public static function forbids(string $ability, array $arguments): bool
    {
        if (! in_array($ability, self::PROTECTED_ABILITIES, true)) {
            return false;
        }

        $target = $arguments[0] ?? null;

        return $target instanceof User && $target->isPlatformSupport();
    }
}
