<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Staff;

use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\AclBootstrapService;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\Feature\FeatureTestCase;

/**
 * A resumed provisioning run repeats the first-admin step, so it must find its own admin and only
 * its own: any other user in the tenant still refuses.
 */
final class CreateFirstAdminIdempotencyTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_repeating_the_call_for_the_same_admin_returns_that_admin(): void
    {
        $service = $this->app->make(AclBootstrapService::class);

        $first  = $service->createFirstAdmin('owner@example.test', 'secret-password', 'Owner');
        $second = $service->createFirstAdmin('Owner@Example.test', 'secret-password', 'Owner');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, User::query()->count());
        $this->assertTrue($second->isAdmin());
        $this->assertTrue(Hash::check('secret-password', $second->password));
    }

    public function test_a_different_email_is_refused_once_the_admin_exists(): void
    {
        $service = $this->app->make(AclBootstrapService::class);
        $service->createFirstAdmin('owner@example.test', 'secret-password', 'Owner');

        $this->expectException(LogicException::class);

        $service->createFirstAdmin('someone@example.test', 'secret-password', 'Someone');
    }

    public function test_a_second_user_makes_even_the_same_email_refuse(): void
    {
        $service = $this->app->make(AclBootstrapService::class);
        $service->createFirstAdmin('owner@example.test', 'secret-password', 'Owner');
        User::factory()->create();

        $this->expectException(LogicException::class);

        $service->createFirstAdmin('owner@example.test', 'secret-password', 'Owner');
    }

    public function test_the_only_user_without_the_admin_role_is_not_taken_for_the_first_admin(): void
    {
        User::factory()->create(['email' => 'owner@example.test']);

        $this->expectException(LogicException::class);

        $this->app->make(AclBootstrapService::class)->createFirstAdmin('owner@example.test', 'secret-password', 'Owner');
    }
}
