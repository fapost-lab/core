<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\PlatformSupportUserService;
use App\Domains\Staff\Support\SupportAccessSession;
use App\Http\Middleware\HandleInertiaRequests;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeTenantAccessMode;
use Tests\Support\RecordingCurrentAssistant;

/**
 * The `admin` and `console` middleware stacks, exercised through probe routes: the account checks Filament makes
 * on every request, the stopped-tenant refusal, the support session and the props shared with every page.
 */
final class ConsoleStackTest extends InertiaConsoleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);

        Route::middleware('admin')->get('admin/_probe', fn () => Inertia::render('Auth/Login', ['action' => 'probe']));
        Route::middleware('admin')->post('admin/_probe', fn (): string => 'written');
        Route::middleware('console')->get('assistant/{tenant}/_probe', fn (): string => 'ok');
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->get($this->panelUrl('/admin/_probe'))->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_a_legacy_numeric_session_user_id_redirects_to_login_instead_of_crashing(): void
    {
        $this->withSession([Auth::guard()->getName() => 1])
            ->get($this->panelUrl('/admin/_probe'))
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertGuest();
    }

    public function test_an_active_user_passes(): void
    {
        $this->actingAs($this->user())->get($this->panelUrl('/admin/_probe'))->assertOk();
    }

    public function test_a_user_who_may_not_use_the_console_gets_403(): void
    {
        foreach ([UserStatus::Pending, UserStatus::Suspended] as $status) {
            $this->actingAs($this->user(['status' => $status]))->get($this->panelUrl('/admin/_probe'))->assertForbidden();
        }
    }

    public function test_a_deactivated_user_with_a_live_session_is_signed_out(): void
    {
        $this->actingAs($this->user(['is_active' => false]))->get($this->panelUrl('/admin/_probe'))->assertUnauthorized();
    }

    public function test_writes_are_refused_while_the_tenant_is_stopped_and_reads_are_not(): void
    {
        $this->app->instance(TenantAccessModeInterface::class, FakeTenantAccessMode::stopped());
        $user = $this->user();

        $this->actingAs($user)->get($this->panelUrl('/admin/_probe'))->assertOk();
        $this->actingAs($user)->postJson($this->panelUrl('/admin/_probe'))->assertStatus(423)->assertJsonPath('error', 'tenant_stopped');
    }

    public function test_the_console_stack_resolves_the_assistant_from_the_tenant_parameter(): void
    {
        $recorder = new RecordingCurrentAssistant();
        $this->app->instance(CurrentAssistantInterface::class, $recorder);
        $assistant = Assistant::factory()->create();
        $user      = $this->user();

        $this->actingAs($user)->get($this->panelUrl("/assistant/{$assistant->getKey()}/_probe"))->assertOk();
        $this->assertTrue($assistant->is($recorder->assistantsSet[0]));

        $this->actingAs($user)
            ->get($this->panelUrl('/assistant/' . mb_strtolower((string) Str::ulid()->toRfc4122()) . '/_probe'))
            ->assertNotFound();
    }

    public function test_a_support_session_that_must_end_goes_to_the_login_with_a_full_page_visit(): void
    {
        config(['tenancy.support_access.enabled' => true]);
        $support = $this->app->make(PlatformSupportUserService::class)->ensure();

        // A support user with no support record in the session is not a legitimate session.
        $this->actingAs($support)
            ->get($this->panelUrl('/admin/_probe'), ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $this->app->make(HandleInertiaRequests::class)->version(request())])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('filament.admin.auth.login'));

        $this->assertGuest();
    }

    public function test_the_shared_props_describe_the_signed_in_user(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->withSession(['success' => 'Saved', 'error' => null])
            ->get($this->panelUrl('/admin/_probe'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.id', (string) $user->getKey())
                ->where('auth.user.name', $user->name)
                ->where('auth.user.email', $user->email)
                ->where('auth.user.isAdmin', true)
                ->has('auth.permissions')
                ->where('flash.success', 'Saved')
                ->where('flash.error', null)
                ->where('locale', 'en')
                ->where('broadcaster.enabled', false)
                ->where('broadcaster.client', null)
                ->where('accessState.mode', 'active')
                ->where('supportAccess', null)
                ->etc());
    }

    public function test_only_a_signed_in_user_is_told_where_the_websocket_is(): void
    {
        config([
            'broadcasting.default'            => 'reverb',
            'broadcasting.connections.reverb' => ['driver' => 'reverb', 'key' => 'app-key', 'secret' => 'app-secret', 'app_id' => '1'],
        ]);

        $this->get($this->panelUrl('/admin/login'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/Login')
                ->where('broadcaster.enabled', true)
                ->where('broadcaster.client', null)
                ->etc());

        $this->actingAs($this->user())
            ->get($this->panelUrl('/admin/_probe'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('broadcaster.enabled', true)
                ->where('broadcaster.client.driver', 'reverb')
                ->where('broadcaster.client.key', 'app-key')
                ->missing('broadcaster.client.secret')
                ->etc());
    }

    public function test_the_permissions_are_the_ones_the_user_holds(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('manage_assistants');

        $this->actingAs($user)
            ->get($this->panelUrl('/admin/_probe'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.isAdmin', false)
                ->where('auth.permissions', ['manage_assistants'])
                ->etc());
    }

    public function test_the_support_access_prop_names_the_operator_only_in_a_support_session(): void
    {
        config(['tenancy.support_access.enabled' => true]);
        $support   = $this->app->make(PlatformSupportUserService::class)->ensure();
        $expiresAt = now()->addHour()->toIso8601String();

        $this->actingAs($support)
            ->withSession([SupportAccessSession::KEY => [
                'entry_id'       => 'entry-1',
                'operator_name'  => 'Olga',
                'operator_email' => 'olga@example.com',
                'expires_at'     => $expiresAt,
            ]])
            ->get($this->panelUrl('/admin/_probe'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('supportAccess.operatorName', 'Olga')
                ->where('supportAccess.operatorEmail', 'olga@example.com')
                ->where('supportAccess.expiresAt', $expiresAt)
                ->etc());
    }

    public function test_a_guest_page_shares_no_user(): void
    {
        $this->get($this->panelUrl('/admin/login'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user', null)
                ->where('auth.permissions', [])
                ->where('supportAccess', null)
                ->etc());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }
}
