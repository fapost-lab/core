<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Http\Middleware\RefuseWritesWhenTenantStopped;
use Database\Seeders\TenantAclSeeder;
use Tests\Feature\Concerns\RunsInHostMode;
use Tests\Feature\FeatureTestCase;

/**
 * In `host` mode the platform settings belong to the operator: the screen answers 404 to everyone, administrators too.
 */
final class AdminPlatformSettingsHostModeTest extends FeatureTestCase
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

    public function test_both_routes_answer_404_to_an_administrator(): void
    {
        // The tenant of this fixture reads as stopped, which refuses writes with 423 before the screen is reached.
        $this->withoutMiddleware(RefuseWritesWhenTenantStopped::class);

        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);
        $url = 'http://main.' . config('tenancy.base_domain') . '/admin/platform-settings';

        $this->actingAs($user)->get($url)->assertNotFound();
        $this->actingAs($user)->put($url, ['flow_execution_max_iterations' => '300'])->assertNotFound();
    }
}
