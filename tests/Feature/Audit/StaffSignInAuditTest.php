<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Models\ActivityEntry;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\PlatformSupportUserService;
use App\Infrastructure\Audit\AuditActorResolver;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * A staff member's sign-in and explicit sign-out are audit entries; the platform support user's are not (the support
 * access log has them), and a trail that cannot write never locks anyone out.
 */
final class StaffSignInAuditTest extends FeatureTestCase
{
    private const string PASSWORD = 'secret-password';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_signing_in_through_the_login_route_and_signing_out_are_recorded(): void
    {
        $user = $this->staff();

        $this->post($this->panelUrl('/admin/login'), ['email' => $user->email, 'password' => self::PASSWORD], ['X-Inertia' => 'true'])
            ->assertStatus(409);
        $this->assertAuthenticatedAs($user);

        $this->post($this->panelUrl('/console/logout'), [], ['X-Inertia' => 'true'])->assertStatus(409);
        $this->assertGuest();

        $entries = ActivityEntry::query()->orderBy('id')->get();

        $this->assertSame(['auth.signed_in', 'auth.signed_out'], $entries->pluck('description')->all());
        $this->assertTrue($entries->every(fn (ActivityEntry $entry): bool => (string) $user->getKey() === $entry->causer_id
            && (string) $user->getKey() === $entry->subject_id));

        $raw = (string) json_encode(ActivityEntry::query()->get()->map->getAttributes());
        $this->assertStringNotContainsString(self::PASSWORD, $raw);
    }

    public function test_the_platform_support_user_signing_in_and_out_writes_nothing(): void
    {
        $support = $this->app->make(PlatformSupportUserService::class)->ensure();
        $guard   = Auth::guard(AuditActorResolver::STAFF_GUARD);

        $guard->login($support);
        $guard->logout();

        $this->assertSame(0, ActivityEntry::query()->count());
    }

    public function test_a_trail_that_throws_does_not_stop_the_sign_in_and_the_error_is_reported(): void
    {
        Exceptions::fake();
        $this->app->instance(AuditTrailInterface::class, new class () implements AuditTrailInterface {
            public function record(AuditRecord $record): void
            {
                throw new RuntimeException('The trail is down.');
            }
        });
        $user = $this->staff();

        $this->post($this->panelUrl('/admin/login'), ['email' => $user->email, 'password' => self::PASSWORD], ['X-Inertia' => 'true'])
            ->assertStatus(409);

        $this->assertAuthenticatedAs($user);
        Exceptions::assertReported(static fn (RuntimeException $e): bool => 'The trail is down.' === $e->getMessage());
        $this->assertSame(0, ActivityEntry::query()->count());
    }

    private function staff(): User
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }
}
