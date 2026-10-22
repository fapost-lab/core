<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Tests\Feature\FeatureTestCase;

/**
 * `viewHorizon` asks the operator access contract: open to permitted staff on a `single` installation,
 * closed in `host` mode, and never served on a tenant host.
 */
final class HorizonOperatorAccessTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_single_an_admin_and_a_permitted_user_open_horizon(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);
        $permitted = User::factory()->create();
        $permitted->givePermissionTo(Permission::ViewOperatorScreens->value);

        $this->actingAs($admin)->get($this->panelUrl('/horizon'))->assertOk();
        $this->actingAs($permitted)->get($this->panelUrl('/horizon'))->assertOk();
    }

    public function test_single_the_platform_support_user_does_not_open_horizon(): void
    {
        $support = User::factory()->create();
        $support->assignRole(RoleEnum::Admin->value);
        $support->forceFill(['is_platform_support' => true])->save();

        // Without a live support-access session the user is signed out and sent away; either way Horizon stays shut.
        $this->actingAs($support->refresh())->get($this->panelUrl('/horizon'))->assertRedirect();
    }

    public function test_single_a_guest_and_a_colleague_without_the_permission_do_not(): void
    {
        $colleague = User::factory()->create();
        $colleague->assignRole(RoleEnum::Analyst->value);

        $this->get($this->panelUrl('/horizon'))->assertForbidden();
        $this->actingAs($colleague)->get($this->panelUrl('/horizon'))->assertForbidden();
    }

    public function test_host_mode_keeps_horizon_closed_on_platform_hosts_and_absent_on_tenant_hosts(): void
    {
        config(['tenancy.resolution' => 'host']);
        $base  = (string) config('tenancy.base_domain');
        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);

        $this->actingAs($admin)->get("http://{$base}/horizon")->assertForbidden();
        $this->actingAs($admin)->get("http://main.{$base}/horizon")->assertNotFound();
        $this->actingAs($admin)->get('http://foreign.example.test/horizon')->assertNotFound();
    }
}
