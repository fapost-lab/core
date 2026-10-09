<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\ConsoleLoginService;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ViewErrorBag;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The Inertia sign-in against what Filament's login page does: the same refusals, throttle, events
 * and session handling, behind `UI_INERTIA=true`.
 */
final class ConsoleLoginTest extends InertiaConsoleTestCase
{
    private const string PASSWORD = 'secret-password';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_the_login_page_is_an_inertia_page_on_the_console_root_view(): void
    {
        $response = $this->get($this->panelUrl('/admin/login'));

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/Login')
                ->where('action', route('console.auth.login.attempt'))
                ->where('auth.user', null)
                ->where('auth.permissions', [])
                ->where('translations.auth.login.submit', 'Sign in')
                ->etc());

        $response->assertViewIs('console');
        $response->assertSee('fapost-theme', false);
    }

    public function test_the_login_page_is_translated_by_the_interface_language(): void
    {
        $this->get($this->panelUrl('/admin/login?lang=ru'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('translations.auth.login.submit', 'Войти')->etc());
    }

    public function test_a_signed_in_user_is_sent_on_instead_of_seeing_the_form(): void
    {
        $this->actingAs($this->user());

        $this->get($this->panelUrl('/admin/login'))->assertRedirect(route('filament.admin.pages.dashboard'));
    }

    public function test_the_assistant_panel_login_leads_to_the_one_sign_in(): void
    {
        $this->get($this->panelUrl('/assistant/login'))->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_signing_in_goes_to_the_dashboard_with_a_full_page_visit(): void
    {
        $user = $this->user();

        $this->post($this->panelUrl('/admin/login'), ['email' => $user->email, 'password' => self::PASSWORD], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('filament.admin.pages.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_intended_address_wins_and_is_used_once(): void
    {
        $user     = $this->user();
        $intended = $this->panelUrl('/admin/assistants');

        $this->withSession(['url.intended' => $intended])
            ->post($this->panelUrl('/admin/login'), ['email' => $user->email, 'password' => self::PASSWORD], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', $intended);

        $this->assertNull(session('url.intended'));
    }

    public function test_without_inertia_the_answer_is_an_ordinary_redirect(): void
    {
        $user = $this->user();

        $this->post($this->panelUrl('/admin/login'), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('filament.admin.pages.dashboard'));
    }

    public function test_the_session_id_is_regenerated_on_sign_in(): void
    {
        $user = $this->user();
        $this->withSession(['probe' => true]);
        $before = session()->getId();

        $this->post($this->panelUrl('/admin/login'), ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->assertNotSame($before, session()->getId());
    }

    public function test_remember_me_sets_the_remember_cookie_and_its_absence_does_not(): void
    {
        $user = $this->user();

        $with = $this->post($this->panelUrl('/admin/login'), ['email' => $user->email, 'password' => self::PASSWORD, 'remember' => true]);
        $this->assertTrue($this->hasRememberCookie($with->headers->getCookies()));

        Auth::forgetGuards();
        Auth::logout();

        $without = $this->post($this->panelUrl('/admin/login'), ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->assertFalse($this->hasRememberCookie($without->headers->getCookies()));
    }

    public function test_the_login_event_fires_once_for_a_successful_sign_in(): void
    {
        $user   = $this->user();
        $logins = 0;
        Event::listen(Login::class, function () use (&$logins): void {
            $logins++;
        });

        $this->post($this->panelUrl('/admin/login'), ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->assertSame(1, $logins);
    }

    public function test_every_refusal_gives_the_same_message_and_fires_failed(): void
    {
        $message = __('filament-panels::auth/pages/login.messages.failed');
        $failed  = 0;
        Event::listen(Failed::class, function () use (&$failed): void {
            $failed++;
        });

        $cases = [
            'wrong password' => [$this->user()->email, 'not-the-password'],
            'unknown email'  => ['nobody@example.com', self::PASSWORD],
            'pending'        => [$this->user(['status' => UserStatus::Pending])->email, self::PASSWORD],
            'suspended'      => [$this->user(['status' => UserStatus::Suspended])->email, self::PASSWORD],
            'deactivated'    => [$this->user(['is_active' => false])->email, self::PASSWORD],
        ];

        foreach ($cases as $label => [$email, $password]) {
            $this->app['session.store']->flush();
            $this->app->make(\Illuminate\Cache\RateLimiter::class)->clear('console-login:127.0.0.1');

            $this->post($this->panelUrl('/admin/login'), ['email' => $email, 'password' => $password])
                ->assertSessionHasErrors(['email' => $message]);

            $this->assertGuest();
            $this->assertSame(['email'], array_keys($this->errorMessages()), $label);
        }

        $this->assertSame(count($cases), $failed);
    }

    public function test_a_refused_user_is_not_signed_in_even_with_the_right_password(): void
    {
        $suspended = $this->user(['status' => UserStatus::Suspended]);

        $this->post($this->panelUrl('/admin/login'), ['email' => $suspended->email, 'password' => self::PASSWORD]);

        $this->assertGuest();
    }

    public function test_missing_fields_are_validation_errors(): void
    {
        $this->post($this->panelUrl('/admin/login'), [])->assertSessionHasErrors(['email', 'password']);
    }

    public function test_the_sixth_attempt_in_a_minute_is_throttled_even_with_the_right_password(): void
    {
        $user = $this->user();

        for ($attempt = 0; $attempt < ConsoleLoginService::MAX_ATTEMPTS; $attempt++) {
            $this->post($this->panelUrl('/admin/login'), ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->post($this->panelUrl('/admin/login'), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            __('filament-panels::auth/pages/login.notifications.throttled.title'),
            $this->errorMessages()['email'][0],
        );
        $this->assertGuest();
    }

    public function test_the_throttle_text_follows_the_interface_language(): void
    {
        $user = $this->user();

        for ($attempt = 0; $attempt <= ConsoleLoginService::MAX_ATTEMPTS; $attempt++) {
            $this->post($this->panelUrl('/admin/login?lang=uk'), ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->assertStringContainsString('Забагато спроб входу', $this->errorMessages()['email'][0]);
    }

    public function test_signing_out_ends_the_session_with_a_full_page_visit(): void
    {
        $this->actingAs($this->user());

        $this->post($this->panelUrl('/console/logout'), [], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('filament.admin.auth.login'));

        $this->assertGuest();
    }

    public function test_signing_out_twice_is_harmless(): void
    {
        $this->post($this->panelUrl('/console/logout'), [], ['X-Inertia' => 'true'])->assertStatus(409);
    }

    /**
     * The validation errors flashed to the session, whether it still holds the bag or its serialized form.
     *
     * @return array<string, list<string>>
     */
    private function errorMessages(): array
    {
        $errors = session('errors');

        if ($errors instanceof ViewErrorBag) {
            return $errors->getBag('default')->getMessages();
        }

        return is_array($errors) ? $errors['default']['messages'] : [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(array $attributes = []): User
    {
        $user = User::factory()->create([...$attributes, 'password' => Hash::make(self::PASSWORD)]);
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    /**
     * @param  list<Cookie>  $cookies
     */
    private function hasRememberCookie(array $cookies): bool
    {
        foreach ($cookies as $cookie) {
            if (str_starts_with($cookie->getName(), 'remember_')) {
                return true;
            }
        }

        return false;
    }
}
