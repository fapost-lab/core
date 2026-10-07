<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Staff;

use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\PlatformSupportUserService;
use App\Domains\Staff\Services\UserService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use Database\Seeders\TenantAclSeeder;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\FeatureTestCase;

/**
 * The tenant's administrators cannot change, demote, deactivate or remove the platform support user.
 */
final class PlatformSupportProtectionTest extends FeatureTestCase
{
    private User $admin;

    private User $support;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());

        $this->admin = User::factory()->create();
        $this->admin->assignRole(RoleEnum::Admin->value);

        $this->support = $this->app->make(PlatformSupportUserService::class)->ensure();
    }

    public function test_the_gate_refuses_every_change_even_for_an_admin(): void
    {
        $gate = Gate::forUser($this->admin);

        foreach (['update', 'updateProfile', 'delete', 'deactivate', 'activate', 'forceDelete', 'restore'] as $ability) {
            $this->assertTrue($gate->denies($ability, $this->support), $ability . ' must be refused');
        }

        $this->assertTrue($gate->denies('updateRoles', [$this->support, []]));
    }

    public function test_the_gate_still_lets_an_admin_change_other_users(): void
    {
        $other = User::factory()->create();
        $gate  = Gate::forUser($this->admin);

        foreach (['update', 'delete', 'deactivate'] as $ability) {
            $this->assertTrue($gate->allows($ability, $other), $ability . ' must stay allowed');
        }
    }

    public function test_viewing_stays_allowed(): void
    {
        $this->assertTrue(Gate::forUser($this->admin)->allows('view', $this->support));
    }

    public function test_the_service_refuses_to_deactivate_or_reactivate_it(): void
    {
        $service = $this->app->make(UserService::class);

        $this->expectException(ValidationException::class);

        try {
            $service->deactivate($this->admin, $this->support);
        } finally {
            $this->assertTrue($this->support->fresh()->is_active);
        }
    }

    public function test_the_service_refuses_to_activate_it(): void
    {
        $this->expectException(ValidationException::class);

        $this->app->make(UserService::class)->activate($this->admin, $this->support);
    }

    public function test_the_support_user_is_not_counted_as_the_last_active_admin(): void
    {
        $service = $this->app->make(UserService::class);
        $second  = User::factory()->create();
        $second->assignRole(RoleEnum::Admin->value);

        // Two real admins: either can be deactivated.
        $service->deactivate($this->admin, $second);
        $this->assertFalse($second->fresh()->is_active);

        // One real admin plus the support user: the real one is still the last.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('last active administrator');

        $service->deactivate($second->fresh(), $this->admin);
    }

    public function test_the_edit_page_is_closed(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('filament.admin.resources.users.edit', ['record' => $this->support]))->assertForbidden();
    }

    public function test_the_edit_page_of_an_ordinary_user_still_offers_delete(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $other = User::factory()->create();

        Livewire::test(EditUser::class, ['record' => $other->getKey()])
            ->assertActionVisible(DeleteAction::class);
    }

    public function test_the_table_marks_it_and_offers_no_actions_on_it(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $other = User::factory()->create();

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$this->admin, $this->support])
            ->assertSee(__('staff.users.platform_support'))
            ->assertTableActionHidden('edit', $this->support)
            ->assertTableActionHidden('deactivate', $this->support)
            ->assertTableActionVisible('deactivate', $other);
    }

    public function test_bulk_delete_skips_the_support_user(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $other = User::factory()->create();

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('delete', [$this->support, $other]);

        $this->assertNotNull(User::query()->find($this->support->getKey()));
        $this->assertNull(User::query()->find($other->getKey()));
    }

    public function test_the_role_of_the_support_user_cannot_be_changed(): void
    {
        $this->assertTrue(Gate::forUser($this->admin)->denies('updateRoles', [$this->support, []]));
        $this->assertTrue($this->support->fresh()->isAdmin());
    }
}
