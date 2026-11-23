<?php

declare(strict_types=1);

namespace App\Domains\Staff\Policies;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\ApiToken;
use App\Domains\Staff\Models\User;

/**
 * The Staff domain's `Gate::before`: what is decided before any policy or permission check, in this order.
 *
 * 1. Nobody, an administrator included, changes or removes the platform support user ({@see PlatformSupportProtection}).
 * 2. The platform support user does nothing with tenant API tokens: not `manage_api_tokens`, and no ability on the
 *    {@see ApiToken} resource (`adr-20261119-tenant-api-tokens` §8). A token would outlive the operator's visit.
 * 3. A tenant API token answers from its abilities and is fail-closed: an ability it holds is `true`, anything else
 *    `false`, never `null`, so no policy written for a person gets a say on it (§7). Abilities a token may never hold
 *    are `false` even when its row lists them.
 * 4. Operator screens are decided by the operator access contract alone, administrators included.
 * 5. Administrators pass everything else.
 *
 * It must run ahead of every other `Gate::before`, the permission package's included, which would otherwise answer
 * `true` for a permission the support user's admin role holds: {@see \App\Providers\StaffServiceProvider} registers it
 * as soon as the Gate is resolved, from `register()`.
 */
final class StaffGate
{
    /**
     * @param  array<array-key, mixed>  $arguments
     * @param  list<string>  $operatorAbilities  the Gate abilities of the operator screens, decided by the operator
     *                                           access contract (the provider passes them: only it may know that class)
     */
    public static function before(mixed $user, string $ability, array $arguments, array $operatorAbilities): ?bool
    {
        if (PlatformSupportProtection::forbids($ability, $arguments)) {
            return false;
        }

        if ($user instanceof User && $user->isPlatformSupport() && self::concernsApiTokens($ability, $arguments)) {
            return false;
        }

        if ($user instanceof ApiToken) {
            return $user->isUsable() && $user->holds($ability);
        }

        if (in_array($ability, $operatorAbilities, true)) {
            return null;
        }

        return $user instanceof User && $user->isAdmin() ? true : null;
    }

    /**
     * Whether the ability is about tenant API tokens: the permission that manages them, or any ability whose subject is
     * a token or the token class.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    public static function concernsApiTokens(string $ability, array $arguments): bool
    {
        if (Permission::ManageApiTokens->value === $ability) {
            return true;
        }

        $subject = $arguments[0] ?? null;

        return $subject instanceof ApiToken || ApiToken::class === $subject;
    }
}
