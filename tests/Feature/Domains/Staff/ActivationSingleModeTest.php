<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Staff;

use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Jobs\SendActivationEmailJob;
use App\Domains\Staff\Mail\ActivationMail;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\ActivationTokenService;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;

/**
 * Staff activation in `single` mode: links keep pointing at `url()`, and `/activate` serves any host.
 */
final class ActivationSingleModeTest extends FeatureTestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = (string) config('tenancy.base_domain');
    }

    public function test_the_mail_links_to_url_of_the_activate_route(): void
    {
        Mail::fake();

        $user  = User::factory()->create(['status' => UserStatus::Pending]);
        $plain = $this->app->make(ActivationTokenService::class)->issue($user);

        (new SendActivationEmailJob('00000000-0000-0000-0000-000000000001', (string) $user->getKey(), $plain))
            ->handle($this->app->make(TenantRepositoryInterface::class), $this->app->make(TenantSwitcher::class));

        Mail::assertSent(ActivationMail::class, fn (ActivationMail $mail): bool => $mail->activationUrl === url('/activate?token=' . $plain)
            && $mail->hasTo($user->email));
    }

    public function test_activate_is_served_on_the_base_domain_and_on_any_host(): void
    {
        $user  = User::factory()->create(['status' => UserStatus::Pending]);
        $plain = $this->app->make(ActivationTokenService::class)->issue($user);

        foreach (["http://{$this->base}", 'http://example.com', 'http://main.' . $this->base] as $origin) {
            $this->get("{$origin}/activate?token={$plain}")
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Activate')->etc());
        }
    }

    /**
     * The base domain serves no console in single mode: the user is sent to the admin dashboard on the default tenant's
     * host, where the panel is (Filament's panel URL pointed at the base domain, which answered 404).
     */
    public function test_activation_on_the_base_domain_signs_the_user_in_and_goes_to_the_panel(): void
    {
        $user  = User::factory()->create(['status' => UserStatus::Pending]);
        $plain = $this->app->make(ActivationTokenService::class)->issue($user);

        $this->post("http://{$this->base}/activate", [
            'token'                 => $plain,
            'password'              => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ])->assertRedirect("http://main.{$this->base}/admin");

        $this->assertSame(UserStatus::Active, $user->fresh()->status);
    }

    public function test_missing_token_shows_the_expired_page_instead_of_failing(): void
    {
        $this->get('/activate')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/ActivationExpired')
                ->where('homeUrl', url('/'))
                ->etc());
    }

    public function test_unknown_token_shows_the_expired_page(): void
    {
        $this->get('/activate?token=nope')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/ActivationExpired')->etc());
    }

    /**
     * A guest page of the console, like the sign-in: the console's root view (theme script included) and its strings in
     * the shared `translations.auth`. The page gets the token its form posts back and the email, nothing else of the user.
     */
    public function test_the_page_is_a_console_guest_page_with_only_what_the_form_needs(): void
    {
        $user  = User::factory()->create(['status' => UserStatus::Pending]);
        $plain = $this->app->make(ActivationTokenService::class)->issue($user);

        $response = $this->get('/activate?token=' . $plain);

        $response->assertOk()
            ->assertViewIs('console')
            ->assertSee('fapost-theme', false)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/Activate')
                ->where('action', route('activate.store'))
                ->where('token', $plain)
                ->where('email', $user->email)
                ->where('auth.user', null)
                ->where('translations.auth.activation.submit', 'Activate')
                ->etc());

        $props = $response->viewData('page')['props'];
        $this->assertArrayNotHasKey('user', $props);
        $this->assertArrayNotHasKey('password', $props);
    }

    public function test_the_pages_follow_the_interface_language(): void
    {
        $user  = User::factory()->create(['status' => UserStatus::Pending]);
        $plain = $this->app->make(ActivationTokenService::class)->issue($user);

        $this->get("/activate?token={$plain}&lang=uk")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('translations.auth.activation.submit', 'Активувати')->etc());

        $this->get('/activate?lang=ru')
            ->assertViewIs('console')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/ActivationExpired')
                ->where('translations.auth.activation.expired.heading', 'Срок действия ссылки активации истёк')
                ->etc());
    }

    /**
     * The form posts as an Inertia request, and the dashboard may be on another host (the default tenant's): a full
     * page visit, not a redirect the browser's XHR would have to follow across origins.
     */
    public function test_an_inertia_activation_goes_to_the_panel_with_a_full_page_visit(): void
    {
        $user  = User::factory()->create(['status' => UserStatus::Pending]);
        $plain = $this->app->make(ActivationTokenService::class)->issue($user);

        $this->post("http://{$this->base}/activate", [
            'token'                 => $plain,
            'password'              => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', "http://main.{$this->base}/admin");

        $this->assertSame(UserStatus::Active, $user->fresh()->status);
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_a_password_that_is_short_or_not_confirmed_is_refused(): void
    {
        $user  = User::factory()->create(['status' => UserStatus::Pending]);
        $plain = $this->app->make(ActivationTokenService::class)->issue($user);

        $this->from('/activate?token=' . $plain)->post('/activate', [
            'token'                 => $plain,
            'password'              => 'short',
            'password_confirmation' => 'short',
        ])->assertRedirect('/activate?token=' . $plain)->assertSessionHasErrors('password');

        $this->from('/activate?token=' . $plain)->post('/activate', [
            'token'                 => $plain,
            'password'              => 'a-long-password',
            'password_confirmation' => 'another-password',
        ])->assertSessionHasErrors('password');

        $this->assertSame(UserStatus::Pending, $user->fresh()->status);
        $this->assertGuest();
    }

    /**
     * A token that still exists for an account that is no longer pending activates nothing: the page is shown again
     * with the error on the token.
     */
    public function test_a_user_who_is_not_pending_is_not_activated_again(): void
    {
        $user  = User::factory()->create(['status' => UserStatus::Suspended]);
        $plain = $this->app->make(ActivationTokenService::class)->issue($user);

        $this->post('/activate', [
            'token'                 => $plain,
            'password'              => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ])->assertRedirect(route('activate.show', ['token' => $plain]))
            ->assertSessionHasErrors(['token' => trans('auth.activation.invalid')]);

        $this->assertSame(UserStatus::Suspended, $user->fresh()->status);
        $this->assertGuest();
    }

    /**
     * An administrator signed in in this browser opens someone's link: the invitee gets a session of their own. The
     * administrator's password hash left in it would make `AuthenticateSession` sign the invitee out on the next request.
     */
    public function test_activating_while_someone_else_is_signed_in_gives_the_invitee_a_session_of_their_own(): void
    {
        $this->seed(TenantAclSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);
        $invitee = User::factory()->create(['status' => UserStatus::Pending]);
        $invitee->assignRole(RoleEnum::Admin->value);
        $plain = $this->app->make(ActivationTokenService::class)->issue($invitee);

        $hashKey = 'password_hash_' . Auth::getDefaultDriver();

        $this->actingAs($admin)
            ->withSession([$hashKey => $admin->getAuthPassword(), 'url.intended' => $this->panelUrl('/admin/users')])
            ->post($this->panelUrl('/activate'), [
                'token'                 => $plain,
                'password'              => 'a-long-password',
                'password_confirmation' => 'a-long-password',
            ])->assertRedirect(route('console.admin.dashboard'));

        $this->assertAuthenticatedAs($invitee);
        $this->assertNotSame($admin->getAuthPassword(), session($hashKey));
        $this->assertNull(session('url.intended'));

        // The next request reads the user from the session, as a browser's would.
        Auth::forgetGuards();

        $this->get($this->panelUrl('/admin'))->assertOk();
        $this->assertAuthenticatedAs($invitee->fresh());
    }
}
