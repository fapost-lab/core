<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Staff;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Jobs\SendActivationEmailJob;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\AclBootstrapService;
use App\Domains\Staff\Services\CreatePendingUserService;
use App\Domains\Staff\Services\UserService;
use App\Domains\Tenancy\Services\UnlimitedTenantLimits;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use LogicException;
use Tests\Feature\Concerns\SetsRecordLimits;
use Tests\Feature\FeatureTestCase;

/**
 * The staff limit counts active accounts, invited (Pending) ones included, and is checked where a
 * place is taken: CreatePendingUserService and UserService::activate. The first admin of a tenant
 * is created without the check.
 */
final class StaffLimitTest extends FeatureTestCase
{
    use SetsRecordLimits;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([SendActivationEmailJob::class]);
        $this->seed(TenantAclSeeder::class);
    }

    public function test_invitation_succeeds_until_the_limit_then_throws(): void
    {
        $admin = $this->actingAsAdmin('admin');
        $this->limitRecords('staff', 2);

        $this->inTenant(function () use ($admin): void {
            $service = app(CreatePendingUserService::class);

            $service->create($admin, $this->invitation('first@example.test'));

            try {
                $service->create($admin, $this->invitation('second@example.test'));
                $this->fail('Expected RecordLimitReachedException.');
            } catch (RecordLimitReachedException $e) {
                $this->assertSame('Staff limit reached: 2 of 2.', $e->getMessage());
            }

            $this->assertSame(2, User::countForLimit());
            $this->assertFalse(User::query()->where('email', 'second@example.test')->exists());
        });
    }

    public function test_default_has_no_limit(): void
    {
        $this->app->instance(TenantLimitsInterface::class, new UnlimitedTenantLimits());
        $admin = $this->actingAsAdmin('admin');

        $this->inTenant(function () use ($admin): void {
            $service = app(CreatePendingUserService::class);

            $service->create($admin, $this->invitation('a@example.test'));
            $service->create($admin, $this->invitation('b@example.test'));

            $this->assertSame(3, User::countForLimit());
        });
    }

    public function test_limit_zero_refuses_an_invitation(): void
    {
        $admin = $this->actingAsAdmin('admin');
        $this->limitRecords('staff', 0);

        $this->inTenant(function () use ($admin): void {
            $this->expectException(RecordLimitReachedException::class);

            app(CreatePendingUserService::class)->create($admin, $this->invitation('first@example.test'));
        });
    }

    public function test_pending_counts_and_deactivated_does_not(): void
    {
        $admin = $this->actingAsAdmin('admin');
        User::factory()->create(['status' => UserStatus::Pending, 'password' => null]);
        User::factory()->create(['is_active' => false]);

        $this->assertSame(2, User::countForLimit(), 'The admin and the pending invitee count, the deactivated user does not.');

        $this->limitRecords('staff', 2);

        $this->inTenant(function () use ($admin): void {
            $this->expectException(RecordLimitReachedException::class);

            app(CreatePendingUserService::class)->create($admin, $this->invitation('extra@example.test'));
        });
    }

    public function test_deactivating_frees_a_place(): void
    {
        $admin  = $this->actingAsAdmin('admin');
        $member = User::factory()->create();
        $this->limitRecords('staff', 2);

        $this->inTenant(function () use ($admin, $member): void {
            app(UserService::class)->deactivate($admin, $member);

            app(CreatePendingUserService::class)->create($admin, $this->invitation('replacement@example.test'));

            $this->assertSame(2, User::countForLimit());
        });
    }

    public function test_activate_checks_the_limit(): void
    {
        $admin       = $this->actingAsAdmin('admin');
        $deactivated = User::factory()->create(['is_active' => false]);
        $this->limitRecords('staff', 1);

        $this->inTenant(function () use ($admin, $deactivated): void {
            try {
                app(UserService::class)->activate($admin, $deactivated);
                $this->fail('Expected RecordLimitReachedException.');
            } catch (RecordLimitReachedException) {
                $this->assertFalse($deactivated->fresh()->is_active);
            }
        });

        $this->limitRecords('staff', 2);

        $this->inTenant(function () use ($admin, $deactivated): void {
            app(UserService::class)->activate($admin, $deactivated);

            $this->assertTrue($deactivated->fresh()->is_active);
        });
    }

    public function test_first_tenant_admin_is_created_even_at_limit_zero(): void
    {
        $this->limitRecords('staff', 0);

        $this->inTenant(function (): void {
            $admin = app(AclBootstrapService::class)->createFirstAdmin('first-admin@example.test', 'secret-password', 'First Admin');

            $this->assertTrue($admin->isAdmin());
            $this->assertSame(1, User::countForLimit(), 'The first admin counts toward the limit.');

            $this->expectException(RecordLimitReachedException::class);
            app(CreatePendingUserService::class)->create($admin, $this->invitation('next@example.test'));
        });
    }

    public function test_first_admin_creation_refuses_a_tenant_that_already_has_users(): void
    {
        User::factory()->create();

        $this->inTenant(function (): void {
            $this->expectException(LogicException::class);

            app(AclBootstrapService::class)->createFirstAdmin('late-admin@example.test', 'secret-password', 'Late Admin');
        });
    }

    public function test_activate_race_shows_a_notification_and_leaves_the_user_deactivated(): void
    {
        $this->actingAsAdmin('admin');
        $deactivated = User::factory()->create(['is_active' => false]);
        $limits      = $this->limitRecords('staff', 2);

        $component = Livewire::test(ListUsers::class);

        // Another request takes the last place after the table was rendered with the action visible.
        // Mounting the action reads the limit twice (its visibility is checked while the table is
        // built and again before it runs); those two reads are answered "no limit", so the service's
        // own check is the first to see the limit.
        User::factory()->create();
        $limits->unlimitedAnswers = 2;

        $component->callTableAction('activate', $deactivated)->assertNotified(__('staff.users.limit.reached_title'));

        $this->assertFalse($deactivated->fresh()->is_active);
    }

    public function test_create_button_and_page_are_closed_for_admin_at_the_limit(): void
    {
        $this->actingAsAdmin('admin');
        $this->limitRecords('staff', 1);

        $this->assertFalse(UserResource::canCreate());
        $this->get($this->panelUrl('/admin/users/create'))->assertForbidden();

        Livewire::test(ListUsers::class)
            ->assertActionHidden('create')
            ->assertSee('Limit reached (1 of 1)');
    }

    public function test_create_button_is_closed_for_staff_with_permission_at_the_limit(): void
    {
        $this->actingAsStaff('admin', Permission::ManageUsers);
        $this->limitRecords('staff', 1);

        $this->assertFalse(UserResource::canCreate());
        $this->get($this->panelUrl('/admin/users/create'))->assertForbidden();
    }

    public function test_create_button_is_visible_below_the_limit(): void
    {
        $this->actingAsAdmin('admin');
        $this->limitRecords('staff', 2);

        $this->assertTrue(UserResource::canCreate());

        Livewire::test(ListUsers::class)
            ->assertActionVisible('create')
            ->assertDontSee('Limit reached');
    }

    public function test_activate_action_is_hidden_at_the_limit(): void
    {
        $this->actingAsAdmin('admin');
        $deactivated = User::factory()->create(['is_active' => false]);

        $this->limitRecords('staff', 2);
        Livewire::test(ListUsers::class)->assertTableActionVisible('activate', $deactivated);

        $this->limitRecords('staff', 1);
        Livewire::test(ListUsers::class)->assertTableActionHidden('activate', $deactivated);
    }

    public function test_race_shows_a_notification_instead_of_an_error(): void
    {
        $this->actingAsAdmin('admin');
        $this->limitRecords('staff', 2);

        $component = Livewire::test(CreateUser::class)
            ->fillForm([
                'name'    => 'Raced',
                'email'   => 'raced@example.test',
                'role_id' => Role::query()->where('name', RoleEnum::ContentManager->value)->firstOrFail()->getKey(),
            ]);

        // Another request takes the last slot after the page was opened below the limit: the stale
        // page must reach the service and get a notification, not a 403.
        User::factory()->create();

        $component->call('create')->assertHasNoFormErrors()->assertNotified(__('staff.users.limit.reached_title'));

        $this->assertFalse(User::query()->where('email', 'raced@example.test')->exists());
    }

    /**
     * @return array{name: string, email: string, phone: null, role_id: int|string}
     */
    private function invitation(string $email): array
    {
        return [
            'name'    => 'Invited',
            'email'   => $email,
            'phone'   => null,
            'role_id' => Role::query()->where('name', RoleEnum::Admin->value)->firstOrFail()->getKey(),
        ];
    }
}
