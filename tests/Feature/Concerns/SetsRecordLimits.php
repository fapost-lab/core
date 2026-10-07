<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Filament\Facades\Filament;
use Tests\Support\FakeTenantLimits;

/**
 * Helpers shared by the record-limit feature tests: answer limits from an array, run code inside
 * the seeded tenant and sign in as an admin or as a staff member holding one permission.
 */
trait SetsRecordLimits
{
    private function limitRecords(string $key, ?int $limit): FakeTenantLimits
    {
        $limits = new FakeTenantLimits([$key => $limit]);

        $this->app->instance(TenantLimitsInterface::class, $limits);

        return $limits;
    }

    private function actingAsAdmin(string $panel): User
    {
        return $this->actingAsStaff($panel);
    }

    /**
     * Signs in as an admin when no permission is given, otherwise as a staff member with exactly those.
     */
    private function actingAsStaff(string $panel, Permission ...$permissions): User
    {
        $this->app->make(TenantContextInterface::class)->set($this->tenant());

        $user = User::factory()->create();
        [] === $permissions
            ? $user->assignRole(RoleEnum::Admin->value)
            : $user->givePermissionTo(array_map(static fn (Permission $permission): string => $permission->value, $permissions));

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel($panel));

        return $user;
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->firstOrFail();
    }

    /**
     * @param  callable(): void  $callback
     */
    private function inTenant(callable $callback): void
    {
        $this->app->make(TenantSwitcher::class)->runForTenant($this->tenant(), $callback);
    }
}
