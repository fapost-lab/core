<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Exceptions\StaffChangeRefusedException;
use App\Domains\Staff\Jobs\SendActivationEmailJob;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\PlatformSupportUserService;
use App\Domains\Staff\Services\StaffUserService;
use App\Domains\Staff\Services\UserService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Concerns\SetsRecordLimits;

/**
 * Staff users in the admin panel, served by the console shell in its admin mode, with every guard of the Filament
 * resource it replaces and the ones that must stop an administrator too: their own account and roles, the last
 * administrator, the platform support user, roles at or above the actor's priority, the staff limit.
 */
final class AdminUsersConsoleTest extends InertiaConsoleTestCase
{
    use SetsRecordLimits;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([SendActivationEmailJob::class]);
        $this->seed(TenantAclSeeder::class);
        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());
    }

    public function test_the_list_shows_the_users_inside_the_admin_shell(): void
    {
        $admin   = $this->admin();
        $support = $this->app->make(PlatformSupportUserService::class)->ensure();
        $other   = $this->staff(RoleEnum::Analyst, ['name' => 'Zed Other']);

        $this->actingAs($admin)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Users/Index')
                ->where('navigation.mode', 'admin')
                ->where('urls.index', '/admin/users')
                ->where('can.create', true)
                ->where('table.meta.total', 3)
                ->where('table.rows', fn ($rows): bool => $this->rowOf($rows, $support)['isSupport']
                    && [] === array_filter($this->rowOf($rows, $support)['can'])
                    && $this->rowOf($rows, $admin)['isSelf']
                    && false === $this->rowOf($rows, $admin)['can']['deactivate']
                    && $this->rowOf($rows, $other)['can']['deactivate']
                    && [] !== $this->rowOf($rows, $other)['roles'])
                ->where('navigation.groups', fn ($groups): bool => collect($groups)
                    ->flatMap(static fn (array $group): array => $group['items'])
                    ->contains(static fn (array $item): bool => 'filament.admin.resources.users.index' === $item['key'] && false === $item['external']))
                ->etc());
    }

    public function test_only_manage_users_opens_the_screen(): void
    {
        $target = $this->staff(RoleEnum::Analyst);
        $denied = User::factory()->create();

        $this->actingAs($denied)->get($this->url())->assertForbidden();
        $this->actingAs($denied)->get($this->url('/create'))->assertForbidden();
        $this->actingAs($denied)->post($this->url(), $this->invitation('x@example.test', $this->roleId(RoleEnum::Analyst)))->assertForbidden();
        $this->actingAs($denied)->get($this->url("/{$target->getKey()}/edit"))->assertForbidden();
        $this->actingAs($denied)->put($this->url("/{$target->getKey()}"), ['name' => 'X', 'email' => $target->email])->assertForbidden();
        $this->actingAs($denied)->delete($this->url("/{$target->getKey()}"))->assertForbidden();
        $this->actingAs($denied)->delete($this->url(), ['ids' => [$target->getKey()]])->assertForbidden();

        $this->assertNotNull($target->fresh());
        $this->assertFalse(User::query()->where('email', 'x@example.test')->exists());

        $this->actingAs($this->manager())->get($this->url())->assertOk();
    }

    public function test_a_visitor_is_sent_to_the_sign_in(): void
    {
        $this->get($this->url())->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_an_invitation_creates_a_pending_user_with_the_role(): void
    {
        $this->actingAs($this->admin())
            ->post($this->url(), $this->invitation('new@example.test', $this->roleId(RoleEnum::ContentManager)))
            ->assertRedirect($this->url())
            ->assertInertiaFlash('success', trans('console.users.invited'));

        $user = User::query()->where('email', 'new@example.test')->firstOrFail();
        $this->assertSame(UserStatus::Pending, $user->status);
        $this->assertTrue($user->hasRole(RoleEnum::ContentManager->value));
        Bus::assertDispatched(SendActivationEmailJob::class);
    }

    public function test_an_administrator_invites_with_any_role_and_others_only_below_their_own(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get($this->url('/create'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('roles', fn ($roles): bool => collect($roles)->contains('value', $this->roleId(RoleEnum::Admin))
                    && collect($roles)->contains('value', $this->roleId(RoleEnum::Analyst)))
                ->etc());

        // Owner's decision (2026-10-10): an administrator may make another administrator.
        $this->actingAs($admin)
            ->post($this->url(), $this->invitation('admin2@example.test', $this->roleId(RoleEnum::Admin)))
            ->assertRedirect($this->url());
        $this->assertTrue(User::query()->where('email', 'admin2@example.test')->firstOrFail()->isAdmin());

        // A manager (priority 50) gives neither their own level nor the administrator role.
        $manager = $this->manager();
        $this->actingAs($manager)
            ->get($this->url('/create'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('roles', fn ($roles): bool => ! collect($roles)->contains('value', $this->roleId(RoleEnum::Admin))
                    && ! collect($roles)->contains('value', $this->roleId(RoleEnum::ContentManager)))
                ->etc());

        foreach ([RoleEnum::ContentManager, RoleEnum::Admin] as $role) {
            $this->actingAs($manager)
                ->post($this->url(), $this->invitation("peer-{$role->value}@example.test", $this->roleId($role)))
                ->assertSessionHasErrors('role_id');
        }

        $this->assertFalse(User::query()->where('email', 'like', 'peer-%')->exists());
    }

    public function test_an_invitation_refuses_a_taken_or_reserved_email(): void
    {
        $taken = $this->staff(RoleEnum::Analyst);

        $this->actingAs($this->admin())
            ->post($this->url(), $this->invitation($taken->email, $this->roleId(RoleEnum::Analyst)))
            ->assertSessionHasErrors('email');

        $this->actingAs($this->admin())
            ->post($this->url(), $this->invitation('someone@example.invalid', $this->roleId(RoleEnum::Analyst)))
            ->assertSessionHasErrors('email');
    }

    public function test_the_staff_limit_closes_inviting_and_activating(): void
    {
        $admin       = $this->admin();
        $deactivated = $this->staff(RoleEnum::Analyst, ['is_active' => false]);
        $this->limitRecords('staff', 1);

        $this->actingAs($admin)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.create', false)
                ->where('limit.reached', true)
                ->where('limit.hint', trans('staff.users.limit.hint', ['current' => 1, 'limit' => 1]))
                ->where('table.rows', fn ($rows): bool => false === $this->rowOf($rows, $deactivated)['can']['activate'])
                ->etc());

        $this->actingAs($admin)->get($this->url('/create'))->assertForbidden();

        // A form opened below the limit and saved after the last place went: a toast, not an error page.
        $this->actingAs($admin)
            ->post($this->url(), $this->invitation('late@example.test', $this->roleId(RoleEnum::Analyst)))
            ->assertRedirect()
            ->assertInertiaFlash('error');
        $this->assertFalse(User::query()->where('email', 'late@example.test')->exists());

        $this->actingAs($admin)
            ->patch($this->url("/{$deactivated->getKey()}/active"), ['active' => true])
            ->assertInertiaFlash('error');
        $this->assertFalse($deactivated->fresh()->is_active);
    }

    public function test_the_profile_and_password_are_saved(): void
    {
        $target = $this->staff(RoleEnum::Analyst);

        $this->actingAs($this->admin())
            ->put($this->url("/{$target->getKey()}"), ['name' => 'Renamed', 'email' => 'renamed@example.test', 'phone' => '+100', 'password' => 'new-secret'])
            ->assertRedirect($this->url())
            ->assertInertiaFlash('success', trans('console.users.updated'));

        $target->refresh();
        $this->assertSame(['Renamed', 'renamed@example.test', '+100'], [$target->name, $target->email, $target->phone]);
        $this->assertTrue(Hash::check('new-secret', (string) $target->password));

        // An empty password keeps the stored one.
        $this->actingAs($this->admin())->put($this->url("/{$target->getKey()}"), ['name' => 'Renamed', 'email' => 'renamed@example.test', 'password' => '']);
        $this->assertTrue(Hash::check('new-secret', (string) $target->fresh()->password));
    }

    public function test_the_email_stays_unique(): void
    {
        $taken  = $this->staff(RoleEnum::Analyst);
        $target = $this->staff(RoleEnum::Analyst);

        $this->actingAs($this->admin())
            ->put($this->url("/{$target->getKey()}"), ['name' => 'X', 'email' => $taken->email])
            ->assertSessionHasErrors('email');
    }

    public function test_someone_elses_profile_needs_a_higher_priority(): void
    {
        $manager = $this->manager();
        $peer    = $this->staff(RoleEnum::ContentManager);
        $lower   = $this->staff(RoleEnum::Analyst);

        // A peer's password would be a way into their account.
        $this->actingAs($manager)->get($this->url("/{$peer->getKey()}/edit"))->assertForbidden();
        $this->actingAs($manager)->put($this->url("/{$peer->getKey()}"), ['name' => 'X', 'email' => $peer->email, 'password' => 'taken-over'])->assertForbidden();
        $this->assertFalse(Hash::check('taken-over', (string) $peer->fresh()->password));

        $this->actingAs($manager)->get($this->url("/{$lower->getKey()}/edit"))->assertOk();

        // An administrator is out of reach for anyone but an administrator.
        $this->actingAs($manager)->get($this->url("/{$this->admin()->getKey()}/edit"))->assertForbidden();
    }

    public function test_a_non_administrator_gives_roles_below_their_own_priority(): void
    {
        $manager = $this->manager();
        $target  = $this->staff(RoleEnum::Analyst);
        $custom  = Role::query()->create(['name' => 'support_agent', 'guard_name' => 'web', 'priority' => 10, 'is_system' => false]);

        $this->actingAs($manager)
            ->get($this->url("/{$target->getKey()}/edit"))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.editRoles', true)
                ->where('roles', fn ($roles): bool => collect($roles)->pluck('value')->sort()->values()->all() === collect([$this->roleId(RoleEnum::Analyst), (string) $custom->getKey()])->sort()->values()->all())
                ->etc());

        $this->actingAs($manager)
            ->put($this->url("/{$target->getKey()}"), ['name' => $target->name, 'email' => $target->email, 'roles' => [(string) $custom->getKey()]])
            ->assertRedirect($this->url());
        $this->assertSame(['support_agent'], $target->fresh()->roles->pluck('name')->all());

        // The manager's own level is out of reach: the policy refuses the whole request.
        $this->actingAs($manager)
            ->put($this->url("/{$target->getKey()}"), ['name' => $target->name, 'email' => $target->email, 'roles' => [$this->roleId(RoleEnum::ContentManager)]])
            ->assertForbidden();
        $this->assertSame(['support_agent'], $target->fresh()->roles->pluck('name')->all());
    }

    public function test_a_non_administrator_cannot_reach_roles_at_or_above_their_own(): void
    {
        $manager = $this->manager();
        $target  = $this->staff(RoleEnum::Analyst);
        $higher  = $this->staff(RoleEnum::Analyst);
        $higher->assignRole(Role::query()->create(['name' => 'lead', 'guard_name' => 'web', 'priority' => 60, 'is_system' => false]));

        $this->actingAs($manager)
            ->put($this->url("/{$target->getKey()}"), ['name' => $target->name, 'email' => $target->email, 'roles' => [$this->roleId(RoleEnum::Admin)]])
            ->assertForbidden();
        $this->assertFalse($target->fresh()->isAdmin());

        // A user holding a role above the manager's is out of reach entirely, so that role cannot be taken away either.
        $this->actingAs($manager)
            ->put($this->url("/{$higher->getKey()}"), ['name' => $higher->name, 'email' => $higher->email, 'roles' => []])
            ->assertForbidden();
        $this->assertTrue($higher->fresh()->hasRole('lead'));
    }

    public function test_an_administrator_grants_the_administrator_role(): void
    {
        $analyst = $this->staff(RoleEnum::Analyst);

        $this->actingAs($this->admin())
            ->put($this->url("/{$analyst->getKey()}"), ['name' => $analyst->name, 'email' => $analyst->email, 'roles' => [$this->roleId(RoleEnum::Admin), $this->roleId(RoleEnum::Analyst)]])
            ->assertRedirect($this->url());

        $this->assertTrue($analyst->fresh()->isAdmin());
    }

    public function test_an_administrator_takes_the_administrator_role_from_another_administrator(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        $this->actingAs($admin)
            ->get($this->url("/{$other->getKey()}/edit"))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.editRoles', true)
                ->where('user.roles', [$this->roleId(RoleEnum::Admin)])
                ->where('user.keptRoles', [])
                ->etc());

        $this->actingAs($admin)
            ->put($this->url("/{$other->getKey()}"), ['name' => $other->name, 'email' => $other->email, 'roles' => [$this->roleId(RoleEnum::Analyst)]])
            ->assertRedirect($this->url());

        $this->assertSame([RoleEnum::Analyst->value], $other->fresh()->roles->pluck('name')->all());
    }

    public function test_the_last_active_administrator_keeps_the_role(): void
    {
        $only = $this->admin();
        // The platform support user is an administrator, but not one of the tenant's: it does not count.
        $support = $this->app->make(PlatformSupportUserService::class)->ensure();

        // Only an operator signed in as the support user can reach this: every admin of the tenant counts themselves.
        try {
            $this->app->make(StaffUserService::class)->update($support, $only, ['name' => 'Renamed', 'email' => $only->email, 'phone' => null, 'password' => null], [$this->roleId(RoleEnum::Analyst)]);
            $this->fail('The last administrator lost the role.');
        } catch (StaffChangeRefusedException $exception) {
            $this->assertSame(StaffChangeRefusedException::LAST_ADMIN, $exception->reason);
        }

        $this->assertTrue($only->fresh()->isAdmin());
        $this->assertNotSame('Renamed', $only->fresh()->name);
    }

    public function test_nobody_changes_their_own_roles(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get($this->url("/{$admin->getKey()}/edit"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.editRoles', false)->etc());

        $this->actingAs($admin)
            ->put($this->url("/{$admin->getKey()}"), ['name' => 'Me', 'email' => $admin->email, 'roles' => [$this->roleId(RoleEnum::Analyst)]])
            ->assertInertiaFlash('error', trans('console.users.refused.own_roles'));

        $this->assertSame([RoleEnum::Admin->value], $admin->fresh()->roles->pluck('name')->all());
        // The transaction took the profile change back with it.
        $this->assertNotSame('Me', $admin->fresh()->name);

        $manager = $this->manager();
        $this->actingAs($manager)
            ->put($this->url("/{$manager->getKey()}"), ['name' => 'Me', 'email' => $manager->email, 'roles' => []])
            ->assertForbidden();
    }

    public function test_an_administrator_saves_their_own_profile(): void
    {
        $admin = $this->admin();

        // What the page sends: no roles, since it shows them read-only for oneself.
        $this->actingAs($admin)
            ->put($this->url("/{$admin->getKey()}"), ['name' => 'Me', 'email' => $admin->email, 'phone' => '', 'password' => 'my-new-secret'])
            ->assertRedirect($this->url())
            ->assertInertiaFlash('success', trans('console.users.updated'));

        $this->assertSame('Me', $admin->fresh()->name);
        $this->assertTrue(Hash::check('my-new-secret', (string) $admin->fresh()->password));

        // Sending one's roles back unchanged is no change, so it does not stop the profile either.
        $this->actingAs($admin)
            ->put($this->url("/{$admin->getKey()}"), ['name' => 'Me again', 'email' => $admin->email, 'roles' => [$this->roleId(RoleEnum::Admin)]])
            ->assertRedirect($this->url());

        $this->assertSame('Me again', $admin->fresh()->name);
        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_the_platform_support_user_cannot_be_changed_or_removed(): void
    {
        $admin   = $this->admin();
        $support = $this->app->make(PlatformSupportUserService::class)->ensure();

        $this->actingAs($admin)->get($this->url("/{$support->getKey()}/edit"))->assertForbidden();
        $this->actingAs($admin)->put($this->url("/{$support->getKey()}"), ['name' => 'X', 'email' => 'x@example.test'])->assertForbidden();
        $this->actingAs($admin)->patch($this->url("/{$support->getKey()}/active"), ['active' => false])->assertForbidden();
        $this->actingAs($admin)->delete($this->url("/{$support->getKey()}"))->assertForbidden();

        $this->actingAs($admin)
            ->delete($this->url(), ['ids' => [$support->getKey()]])
            ->assertInertiaFlash('error');

        $support->refresh();
        $this->assertTrue($support->is_active);
        $this->assertTrue($support->isAdmin());
    }

    public function test_deactivating_signs_out_and_activating_brings_back(): void
    {
        $admin  = $this->admin();
        $target = $this->staff(RoleEnum::Analyst);
        DB::table('sessions')->insert(['id' => 'session-1', 'user_id' => $target->getKey(), 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($admin)
            ->patch($this->url("/{$target->getKey()}/active"), ['active' => false])
            ->assertInertiaFlash('success', trans('console.users.deactivated'));

        $this->assertFalse($target->fresh()->is_active);
        $this->assertFalse(DB::table('sessions')->where('user_id', $target->getKey())->exists());

        $this->actingAs($admin)
            ->patch($this->url("/{$target->getKey()}/active"), ['active' => true])
            ->assertInertiaFlash('success', trans('console.users.activated'));
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_nobody_deactivates_themselves_and_only_an_administrator_changes_access(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch($this->url("/{$admin->getKey()}/active"), ['active' => false])
            ->assertInertiaFlash('error');
        $this->assertTrue($admin->fresh()->is_active);

        $target = $this->staff(RoleEnum::Analyst);
        $this->actingAs($this->manager())->patch($this->url("/{$target->getKey()}/active"), ['active' => false])->assertForbidden();
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_the_activation_email_is_sent_again_at_most_every_five_minutes(): void
    {
        $admin   = $this->admin();
        $pending = $this->staff(RoleEnum::Analyst, ['status' => UserStatus::Pending, 'password' => null]);
        $active  = $this->staff(RoleEnum::Analyst);

        $this->actingAs($admin)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows', fn ($rows): bool => $this->rowOf($rows, $pending)['can']['resend'] && ! $this->rowOf($rows, $active)['can']['resend'])
                ->etc());

        $this->actingAs($admin)
            ->post($this->url("/{$pending->getKey()}/resend-activation"))
            ->assertInertiaFlash('success', trans('console.users.activation_sent'));
        Bus::assertDispatchedTimes(SendActivationEmailJob::class, 1);

        $this->actingAs($admin)->post($this->url("/{$pending->getKey()}/resend-activation"))->assertInertiaFlash('error');
        $this->actingAs($admin)->post($this->url("/{$active->getKey()}/resend-activation"))->assertInertiaFlash('error');
        Bus::assertDispatchedTimes(SendActivationEmailJob::class, 1);
    }

    public function test_a_user_is_deleted_from_the_edit_page_but_not_oneself(): void
    {
        $admin  = $this->admin();
        $target = $this->staff(RoleEnum::Analyst);

        $this->actingAs($admin)
            ->delete($this->url("/{$target->getKey()}"))
            ->assertRedirect($this->url())
            ->assertInertiaFlash('success', trans('console.users.deleted'));
        $this->assertNull($target->fresh());

        $this->actingAs($admin)
            ->get($this->url("/{$admin->getKey()}/edit"))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.delete', false)->etc());

        $this->actingAs($admin)
            ->delete($this->url("/{$admin->getKey()}"))
            ->assertInertiaFlash('error', trans('console.users.refused.own_account'));
        $this->assertNotNull($admin->fresh());
    }

    public function test_a_bulk_delete_authorizes_and_guards_each_user(): void
    {
        $manager = $this->manager();
        $admin   = $this->admin();
        $lower   = $this->staff(RoleEnum::Analyst);

        $this->actingAs($manager)
            ->delete($this->url(), ['ids' => [$admin->getKey(), $lower->getKey(), $manager->getKey()]])
            ->assertInertiaFlash('success', trans('console.users.deleted_partly', ['deleted' => 1, 'blocked' => 2]));

        $this->assertNull($lower->fresh());
        $this->assertNotNull($admin->fresh());
        $this->assertNotNull($manager->fresh());
    }

    public function test_the_last_active_administrator_is_never_deleted_or_deactivated(): void
    {
        $only    = $this->admin();
        $support = $this->app->make(PlatformSupportUserService::class)->ensure();

        try {
            $this->app->make(UserService::class)->deactivate($support, $only);
            $this->fail('The last administrator was deactivated.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('last active administrator', $exception->getMessage());
        }

        $this->assertNotNull($only->fresh());
        $this->assertTrue($only->fresh()->is_active);

        // A deactivated administrator is not the one keeping the tenant reachable.
        $inactive = $this->staff(RoleEnum::Admin, ['is_active' => false]);
        $this->app->make(StaffUserService::class)->delete($only, $inactive);
        $this->assertNull($inactive->fresh());

        try {
            $this->app->make(StaffUserService::class)->delete($support, $only);
            $this->fail('The last administrator was deleted.');
        } catch (StaffChangeRefusedException $exception) {
            $this->assertSame(StaffChangeRefusedException::LAST_ADMIN, $exception->reason);
        }
    }

    public function test_a_pending_administrator_invitation_does_not_count_as_another_administrator(): void
    {
        $only    = $this->admin();
        $support = $this->app->make(PlatformSupportUserService::class)->ensure();
        $this->staff(RoleEnum::Admin, ['status' => UserStatus::Pending, 'password' => null]);

        $refusals = [
            fn () => $this->app->make(StaffUserService::class)->update($support, $only, ['name' => $only->name, 'email' => $only->email, 'phone' => null, 'password' => null], [$this->roleId(RoleEnum::Analyst)]),
            fn () => $this->app->make(StaffUserService::class)->delete($support, $only),
        ];

        foreach ($refusals as $attempt) {
            try {
                $attempt();
                $this->fail('The last accepted administrator was removed.');
            } catch (StaffChangeRefusedException $exception) {
                $this->assertSame(StaffChangeRefusedException::LAST_ADMIN, $exception->reason);
            }
        }

        try {
            $this->app->make(UserService::class)->deactivate($support, $only);
            $this->fail('The last accepted administrator was deactivated.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('last active administrator', $exception->getMessage());
        }

        $only->refresh();
        $this->assertTrue($only->is_active);
        $this->assertTrue($only->isAdmin());
    }

    public function test_two_administrators_cannot_delete_each_other_one_after_the_other(): void
    {
        $first  = $this->admin();
        $second = $this->admin();

        $this->actingAs($first)->delete($this->url("/{$second->getKey()}"))->assertInertiaFlash('success', trans('console.users.deleted'));

        // The survivor is now the last one; nobody can take them out.
        $support = $this->app->make(PlatformSupportUserService::class)->ensure();

        try {
            $this->app->make(StaffUserService::class)->delete($support, $first);
            $this->fail('The last administrator was deleted.');
        } catch (StaffChangeRefusedException $exception) {
            $this->assertSame(StaffChangeRefusedException::LAST_ADMIN, $exception->reason);
        }

        $this->assertNotNull($first->fresh());
    }

    public function test_deleting_someone_needs_a_higher_priority(): void
    {
        $manager = $this->manager();
        $peer    = $this->staff(RoleEnum::ContentManager);
        $lower   = $this->staff(RoleEnum::Analyst);

        $this->actingAs($manager)->delete($this->url("/{$peer->getKey()}"))->assertForbidden();
        $this->assertNotNull($peer->fresh());

        $this->actingAs($manager)->delete($this->url("/{$lower->getKey()}"))->assertRedirect($this->url());
        $this->assertNull($lower->fresh());
    }

    private function url(string $suffix = ''): string
    {
        return $this->panelUrl("/admin/users{$suffix}");
    }

    private function admin(): User
    {
        return $this->staff(RoleEnum::Admin);
    }

    /**
     * A content manager (priority 50) who may manage users, but is no administrator.
     */
    private function manager(): User
    {
        $user = $this->staff(RoleEnum::ContentManager);
        $user->givePermissionTo(Permission::ManageUsers->value);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function staff(RoleEnum $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role->value);

        return $user;
    }

    private function roleId(RoleEnum $role): string
    {
        return (string) Role::query()->where('name', $role->value)->value('id');
    }

    /**
     * @return array<string, string>
     */
    private function invitation(string $email, string $roleId): array
    {
        return ['name' => 'Invited', 'email' => $email, 'phone' => '', 'role_id' => $roleId];
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     *
     * @return array<string, mixed>
     */
    private function rowOf(iterable $rows, User $user): array
    {
        foreach ($rows as $row) {
            if ($row['id'] === (string) $user->getKey()) {
                return $row;
            }
        }

        $this->fail("No row for {$user->email}.");
    }
}
