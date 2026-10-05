<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Staff;

use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Jobs\SendActivationEmailJob;
use App\Domains\Staff\Mail\ActivationMail;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\ActivationTokenService;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Support\Facades\Mail;
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
                ->assertViewIs('staff.activate');
        }
    }

    public function test_activation_on_the_base_domain_signs_the_user_in_and_goes_to_the_panel(): void
    {
        $user  = User::factory()->create(['status' => UserStatus::Pending]);
        $plain = $this->app->make(ActivationTokenService::class)->issue($user);

        $this->post("http://{$this->base}/activate", [
            'token'                 => $plain,
            'password'              => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ])->assertRedirectContains('/admin');

        $this->assertSame(UserStatus::Active, $user->fresh()->status);
    }

    public function test_missing_token_shows_the_expired_page_instead_of_failing(): void
    {
        $this->get('/activate')
            ->assertOk()
            ->assertViewIs('staff.activation-expired')
            ->assertSee(url('/'));
    }

    public function test_unknown_token_shows_the_expired_page(): void
    {
        $this->get('/activate?token=nope')
            ->assertOk()
            ->assertViewIs('staff.activation-expired');
    }
}
