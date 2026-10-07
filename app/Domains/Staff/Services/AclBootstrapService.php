<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Models\User;
use Database\Seeders\RoleSeeder;
use LogicException;
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
     * refuses to run once the tenant has any user, and only provisioning may depend on this class
     * (`Tests\Architecture\CountableModelCreationTest`).
     *
     * @throws LogicException when the tenant already has a user
     */
    public function createFirstAdmin(string $email, #[SensitiveParameter] string $password, string $name): User
    {
        if (User::query()->exists()) {
            throw new LogicException('The first admin can be created only in a tenant without users.');
        }

        $user = User::query()->create([
            'name'              => $name,
            'email'             => $email,
            'password'          => $password,
            'email_verified_at' => now(),
            'status'            => UserStatus::Active,
        ]);

        $user->assignRole('admin');

        return $user;
    }
}
