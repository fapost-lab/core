<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantAdminDirectoryInterface;
use App\Domains\Tenancy\ValueObjects\TenantAdmin;
use Illuminate\Support\Str;

/**
 * Answers Tenancy's question "is this person an administrator of the tenant": an active user holding the Admin role who
 * is not the platform support user. Reads the current tenant's schema.
 */
final readonly class TenantAdminDirectory implements TenantAdminDirectoryInterface
{
    public function find(string $userId): ?TenantAdmin
    {
        // A non-uuid id must not reach a `uuid` column, where Postgres would refuse it.
        if (! Str::isUuid($userId)) {
            return null;
        }

        $user = User::query()
            ->withoutPlatformSupport()
            ->where('is_active', true)
            ->role(RoleEnum::Admin->value)
            ->find($userId);

        return $user instanceof User ? new TenantAdmin((string) $user->getKey(), $user->name, $user->email) : null;
    }
}
