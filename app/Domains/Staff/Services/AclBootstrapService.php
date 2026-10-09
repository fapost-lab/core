<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Exceptions\FirstAdminConflictException;
use App\Domains\Staff\Models\User;
use Database\Seeders\RoleSeeder;
use SensitiveParameter;

/**
 * Bootstraps a tenant's staff: the role seed and the first admin account, called by
 * {@see \App\Domains\Tenancy\Services\TenantProvisioningService}.
 * The role logic lives in {@see RoleSeeder}.
 */
final class AclBootstrapService
{
    public function bootstrap(): void
    {
        (new RoleSeeder())->run();
    }

    /**
     * Create the tenant's first administrator.
     *
     * The one deliberate exception to the staff limit: it is created before any limit could be
     * known and a tenant without an admin cannot be used, so it does not go through the
     * limit check that {@see CreatePendingUserService} makes. The limit still counts this account.
     * Must run inside a tenant switch and after {@see bootstrap()}. Because it skips the check, it
     * refuses to run once the tenant has users other than that admin, and only provisioning may
     * depend on this class (`Tests\Architecture\CountableModelCreationTest`).
     *
     * Repeating the call is safe, as a resumed provisioning run does: when the tenant's only user is
     * this admin (same e-mail, case aside) holding the admin role, that user is returned. The account
     * and its role are created in one transaction, so an interrupted run leaves neither half.
     *
     * @throws FirstAdminConflictException (a LogicException) when the tenant already has a user other than the first admin
     */
    public function createFirstAdmin(string $email, #[SensitiveParameter] string $password, string $name): User
    {
        $users = User::query()->limit(2)->get();

        if ($users->isNotEmpty()) {
            $existing = $users->first();

            if (1 === $users->count() && 0 === strcasecmp($existing->email, $email) && $existing->hasRole('admin')) {
                return $existing;
            }

            throw FirstAdminConflictException::tenantHasUsers();
        }

        return User::query()->getConnection()->transaction(static function () use ($email, $password, $name): User {
            $user = User::query()->create([
                'name'              => $name,
                'email'             => $email,
                'password'          => $password,
                'email_verified_at' => now(),
                'status'            => UserStatus::Active,
            ]);

            $user->assignRole('admin');

            return $user;
        });
    }
}
