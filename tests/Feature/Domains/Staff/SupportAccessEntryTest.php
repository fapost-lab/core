<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Staff;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Models\SupportAccessEntry;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\PlatformSupportUserService;
use App\Domains\Staff\Support\SupportAccessSession;
use App\Domains\Tenancy\Services\SupportAccessTokenStore;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Tenancy\DTO\SupportAccessRequest;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\Feature\FeatureTestCase;

/**
 * The support entry end to end: grant, POST with the token in the body, the platform support user, the session.
 */
final class SupportAccessEntryTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        config(['tenancy.support_access.enabled' => true]);
    }

    public function test_the_token_travels_in_the_body_and_never_in_a_url(): void
    {
        $token = $this->token();

        // A link cannot enter: the route accepts only a POST.
        $this->get($this->enterUrl() . '?token=' . $token)->assertStatus(405);

        $response = $this->post($this->enterUrl(), ['token' => $token]);

        $response->assertRedirect();
        $this->assertStringNotContainsString($token, (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString('token', (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString($token, (string) $response->getContent());
    }

    public function test_entering_signs_in_the_platform_support_user_with_a_support_session(): void
    {
        $token = $this->token();

        $this->post($this->enterUrl(), ['token' => $token])
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $user = Auth::user();
        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->isPlatformSupport());

        $session = new SupportAccessSession($this->app['session.store']);
        $current = $session->current();
        $this->assertNotNull($current);
        $this->assertSame('Olga', $current['operator_name']);
        $this->assertSame('olga@example.com', $current['operator_email']);
        $this->assertEqualsWithDelta(
            SupportAccessSession::LIFETIME_MINUTES * 60,
            Carbon::parse($current['expires_at'])->getTimestamp() - time(),
            5,
        );
    }

    public function test_the_session_id_is_regenerated_and_there_is_no_remember_me(): void
    {
        $before = $this->app['session.store']->getId();

        $this->post($this->enterUrl(), ['token' => $this->token()])->assertRedirect();

        $this->assertNotSame($before, $this->app['session.store']->getId());
        $this->assertNull(User::query()->where('is_platform_support', true)->sole()->remember_token);
    }

    public function test_the_entry_is_recorded_for_the_tenant(): void
    {
        $this->post($this->enterUrl(), ['token' => $this->token()])->assertRedirect();

        $entry = SupportAccessEntry::query()->sole();
        $this->assertSame('operator:7', $entry->operator_ref);
        $this->assertSame('Olga', $entry->operator_name);
        $this->assertSame('olga@example.com', $entry->operator_email);
        $this->assertNotNull($entry->entered_at);
        $this->assertNull($entry->left_at);
        $this->assertNotNull($entry->ip);
    }

    public function test_the_support_user_is_an_admin_without_a_password_and_created_once(): void
    {
        $this->post($this->enterUrl(), ['token' => $this->token()])->assertRedirect();
        $this->flushSession();
        Auth::forgetGuards();
        $this->post($this->enterUrl(), ['token' => $this->token()])->assertRedirect();

        $support = User::query()->where('is_platform_support', true)->get();

        $this->assertCount(1, $support);
        $user = $support->first();
        $this->assertSame(PlatformSupportUserService::NAME, $user->name);
        $this->assertSame(PlatformSupportUserService::EMAIL, $user->email);
        $this->assertStringEndsWith('.invalid', $user->email);
        $this->assertNull($user->password);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->hasRole(RoleEnum::Admin->value));
        $this->assertSame(2, SupportAccessEntry::query()->count());
    }

    public function test_the_support_user_cannot_sign_in_through_the_form(): void
    {
        $this->post($this->enterUrl(), ['token' => $this->token()])->assertRedirect();
        $this->post(route('support.leave'))->assertRedirect();
        Auth::forgetGuards();

        foreach (['', 'password', 'secret-secret'] as $password) {
            $this->assertFalse(Auth::guard()->attempt(['email' => PlatformSupportUserService::EMAIL, 'password' => $password]));
        }

        $this->assertGuest();
    }

    public function test_the_token_works_once(): void
    {
        $token = $this->token();

        $this->post($this->enterUrl(), ['token' => $token])->assertRedirect();
        $this->flushSession();
        Auth::forgetGuards();

        $this->post($this->enterUrl(), ['token' => $token])->assertForbidden();
        $this->assertGuest();
        $this->assertSame(1, SupportAccessEntry::query()->count());
    }

    public function test_an_expired_token_does_not_enter(): void
    {
        $token = $this->token();

        Carbon::setTestNow(now()->addSeconds(61));

        try {
            $this->post($this->enterUrl(), ['token' => $token])->assertForbidden();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertGuest();
        $this->assertSame(0, User::query()->where('is_platform_support', true)->count());
    }

    public function test_a_missing_or_wrong_token_does_not_enter(): void
    {
        $this->post($this->enterUrl())->assertForbidden();
        $this->post($this->enterUrl(), ['token' => 'wrong'])->assertForbidden();
        $this->post($this->enterUrl(), ['token' => ['a']])->assertForbidden();

        $this->assertGuest();
    }

    public function test_without_the_flag_the_route_is_not_found_and_nothing_is_consumed(): void
    {
        $token = $this->token();
        config(['tenancy.support_access.enabled' => false]);

        $this->post($this->enterUrl(), ['token' => $token])->assertNotFound();
        $this->assertGuest();

        config(['tenancy.support_access.enabled' => true]);
        $this->post($this->enterUrl(), ['token' => $token])->assertRedirect();
    }

    public function test_the_enter_route_is_exempt_from_csrf_and_throttled(): void
    {
        $this->assertContains('support/enter', $this->app->make(VerifyCsrfToken::class)->getExcludedPaths());

        $route = Route::getRoutes()->getByName('support.enter');
        $this->assertContains('throttle:20,1', $route->gatherMiddleware());
    }

    public function test_the_banner_names_the_operator_while_the_session_lasts(): void
    {
        $this->post($this->enterUrl(), ['token' => $this->token()])->assertRedirect();

        $this->get(route('filament.admin.pages.dashboard'))
            ->assertOk()
            ->assertSee(__('staff.support_access.banner', ['name' => 'Olga', 'email' => 'olga@example.com']))
            ->assertSee(route('support.leave'), false);
    }

    public function test_there_is_no_banner_for_an_ordinary_session(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);

        $this->actingAs($admin)
            ->get(route('filament.admin.pages.dashboard'))
            ->assertOk()
            ->assertDontSee(route('support.leave'), false);
    }

    public function test_the_banner_is_also_on_the_assistant_panel(): void
    {
        $this->post($this->enterUrl(), ['token' => $this->token()])->assertRedirect();

        $assistant = Assistant::factory()->create();

        $this->get(route('filament.assistant.pages.dashboard', ['tenant' => $assistant]))
            ->assertOk()
            ->assertSee('olga@example.com');
    }

    public function test_leaving_closes_the_entry_and_ends_the_session(): void
    {
        $this->post($this->enterUrl(), ['token' => $this->token()])->assertRedirect();

        $this->post(route('support.leave'))->assertRedirect(route('filament.admin.auth.login'));

        $this->assertGuest();
        $this->assertNotNull(SupportAccessEntry::query()->sole()->left_at);
        $this->assertNull((new SupportAccessSession($this->app['session.store']))->current());
    }

    public function test_only_a_support_session_can_use_the_leave_route(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->post(route('support.leave'))->assertNotFound();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_normal_sign_out_also_closes_the_entry(): void
    {
        $this->post($this->enterUrl(), ['token' => $this->token()])->assertRedirect();

        Auth::guard()->logout();

        $this->assertNotNull(SupportAccessEntry::query()->sole()->left_at);
    }

    public function test_the_session_ends_after_an_hour(): void
    {
        $this->post($this->enterUrl(), ['token' => $this->token()])->assertRedirect();

        Carbon::setTestNow(now()->addMinutes(SupportAccessSession::LIFETIME_MINUTES - 1));
        $this->get(route('filament.admin.pages.dashboard'))->assertOk();

        Carbon::setTestNow(now()->addMinutes(2));

        try {
            $this->get(route('filament.admin.pages.dashboard'))->assertRedirect(route('filament.admin.auth.login'));
        } finally {
            Carbon::setTestNow();
        }

        $this->assertGuest();
        $this->assertNotNull(SupportAccessEntry::query()->sole()->left_at);
    }

    public function test_activity_does_not_extend_the_hour(): void
    {
        $this->post($this->enterUrl(), ['token' => $this->token()])->assertRedirect();
        $expires = (new SupportAccessSession($this->app['session.store']))->current()['expires_at'];

        $this->get(route('filament.admin.pages.dashboard'))->assertOk();

        $this->assertSame($expires, (new SupportAccessSession($this->app['session.store']))->current()['expires_at']);
    }

    public function test_entering_while_signed_in_as_someone_else_replaces_that_session(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);
        $this->actingAs($admin);

        $this->post($this->enterUrl(), ['token' => $this->token()])->assertRedirect();

        $this->assertTrue(Auth::user()->isPlatformSupport());
    }

    public function test_a_token_in_the_query_string_is_refused_and_not_consumed(): void
    {
        $token = $this->token();

        $this->post($this->enterUrl() . '?token=' . $token)->assertForbidden();
        $this->assertGuest();

        $this->post($this->enterUrl(), ['token' => $token])->assertRedirect();
    }

    public function test_an_expired_support_cookie_does_not_swallow_a_fresh_grant(): void
    {
        $this->post($this->enterUrl(), ['token' => $this->token()])->assertRedirect();

        Carbon::setTestNow(now()->addMinutes(SupportAccessSession::LIFETIME_MINUTES + 5));

        try {
            $fresh = $this->token();
            $this->post($this->enterUrl(), ['token' => $fresh])->assertRedirect(route('filament.admin.pages.dashboard'));
        } finally {
            Carbon::setTestNow();
        }

        $this->assertTrue(Auth::user()->isPlatformSupport());
        $this->assertSame(2, SupportAccessEntry::query()->count());
        $this->assertNotNull((new SupportAccessSession($this->app['session.store']))->current());
    }

    public function test_switching_the_flag_off_ends_open_support_sessions(): void
    {
        $this->post($this->enterUrl(), ['token' => $this->token()])->assertRedirect();
        $this->get(route('filament.admin.pages.dashboard'))->assertOk();

        config(['tenancy.support_access.enabled' => false]);

        $this->get(route('filament.admin.pages.dashboard'))->assertRedirect(route('filament.admin.auth.login'));
        $this->assertGuest();
        $this->assertNotNull(SupportAccessEntry::query()->sole()->left_at);
    }

    public function test_a_support_user_without_a_support_record_is_signed_out(): void
    {
        $support = $this->app->make(PlatformSupportUserService::class)->ensure();

        $this->actingAs($support)->get(route('filament.admin.pages.dashboard'))
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertGuest();
    }

    public function test_the_banner_is_not_shown_to_an_ordinary_user_with_a_forged_support_record(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);

        $this->actingAs($admin)
            ->withSession(['support_access' => [
                'entry_id'   => 'x', 'operator_name' => 'Mallory', 'operator_email' => 'm@example.com',
                'expires_at' => now()->addHour()->toIso8601String(),
            ]])
            ->get(route('filament.admin.pages.dashboard'))
            ->assertDontSee('Mallory');
    }

    public function test_expiry_answers_each_kind_of_request_in_its_own_way(): void
    {
        $url   = route('filament.admin.pages.dashboard');
        $login = route('filament.admin.auth.login');

        $cases = [
            'inertia'  => [['X-Inertia' => 'true'], fn ($response) => $response->assertStatus(409)->assertHeader('X-Inertia-Location', $login)],
            'json'     => [['Accept' => 'application/json'], fn ($response) => $response->assertUnauthorized()],
            'livewire' => [['X-Livewire' => 'true'], fn ($response) => $response->assertStatus(419)],
            'browser'  => [[], fn ($response) => $response->assertRedirect($login)],
        ];

        foreach ($cases as [$headers, $assert]) {
            $this->flushSession();
            Auth::forgetGuards();
            $this->post($this->enterUrl(), ['token' => $this->token()])->assertRedirect();

            Carbon::setTestNow(now()->addMinutes(SupportAccessSession::LIFETIME_MINUTES + 1));

            try {
                $assert($this->withHeaders($headers)->get($url));
            } finally {
                Carbon::setTestNow();
                $this->flushHeaders();
            }
        }
    }

    public function test_the_support_address_cannot_be_given_to_a_new_user(): void
    {
        $this->assertTrue(PlatformSupportUserService::isReservedEmail('Support@Platform.INVALID '));
        $this->assertTrue(PlatformSupportUserService::isReservedEmail('x@anything.invalid'));
        $this->assertFalse(PlatformSupportUserService::isReservedEmail('x@example.com'));

        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->app->make(\App\Domains\Staff\Services\CreatePendingUserService::class)->create($admin, [
            'name'    => 'Imposter', 'email' => PlatformSupportUserService::EMAIL, 'phone' => null,
            'role_id' => \App\Domains\Staff\Models\Role::query()->where('name', RoleEnum::Admin->value)->value('id'),
        ]);
    }

    public function test_abandoned_entries_are_closed_when_their_hour_was_up(): void
    {
        $old    = SupportAccessEntry::query()->create(['operator_ref' => 'o:1', 'operator_name' => 'A', 'operator_email' => 'a@example.com', 'entered_at' => now()->subHours(3)]);
        $recent = SupportAccessEntry::query()->create(['operator_ref' => 'o:2', 'operator_name' => 'B', 'operator_email' => 'b@example.com', 'entered_at' => now()->subMinutes(10)]);

        $this->artisan('support-access:prune')->assertSuccessful();

        $this->assertEquals($old->entered_at->addMinutes(60)->timestamp, $old->fresh()->left_at->timestamp);
        $this->assertNull($recent->fresh()->left_at);
    }

    public function test_the_support_user_row_cannot_be_deleted(): void
    {
        $support = $this->app->make(PlatformSupportUserService::class)->ensure();

        $this->assertFalse($support->delete());
        $this->assertNotNull(User::query()->find($support->getKey()));
    }

    /**
     * A token as Core issues it. Grants themselves are issued in host mode only (see the issue test).
     */
    private function token(): string
    {
        return $this->app->make(SupportAccessTokenStore::class)
            ->issue(new SupportAccessRequest(self::TENANT_ID, 'operator:7', 'Olga', 'olga@example.com'))['token'];
    }

    private function enterUrl(): string
    {
        return route('support.enter');
    }
}
