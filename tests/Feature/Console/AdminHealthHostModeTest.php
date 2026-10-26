<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Http\Middleware\RefuseWritesWhenTenantStopped;
use App\Http\Shell\NavigationBuilder;
use Database\Seeders\TenantAclSeeder;
use Tests\Feature\Concerns\RunsInHostMode;
use Tests\Feature\FeatureTestCase;

/**
 * In `host` mode the health page does not exist: its figures are the whole installation's, and a tenant's administrator
 * must not read other tenants' channels from them. The tenant's own slice is a separate screen.
 */
final class AdminHealthHostModeTest extends FeatureTestCase
{
    use RunsInHostMode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provisionSecondTenant();
        $this->seed(TenantAclSeeder::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreTenancyResolution();
    }

    public function test_the_page_answers_404_to_an_administrator(): void
    {
        $this->withoutMiddleware(RefuseWritesWhenTenantStopped::class);

        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        $this->actingAs($user)->get('http://main.' . config('tenancy.base_domain') . '/admin/health')->assertNotFound();
    }

    public function test_the_menu_does_not_offer_it_and_a_user_without_rights_gets_404_too(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);

        $this->assertStringNotContainsString('console.admin.health.index', json_encode(app(NavigationBuilder::class)->admin($admin), JSON_THROW_ON_ERROR));

        $this->withoutMiddleware(RefuseWritesWhenTenantStopped::class);
        $this->actingAs(User::factory()->create())->get('http://main.' . config('tenancy.base_domain') . '/admin/health')->assertNotFound();
    }
}
