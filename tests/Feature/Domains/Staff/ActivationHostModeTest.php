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
use Fapost\Foundation\Tenancy\Contracts\TenantRenamerInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Concerns\RunsInHostMode;
use Tests\Feature\FeatureTestCase;

/**
 * Staff activation in `host` mode: the link names the tenant's own host, and only that host serves it.
 *
 * Activation tokens live in the tenant schema, so the base domain, which has no tenant, cannot find them.
 */
final class ActivationHostModeTest extends FeatureTestCase
{
    use RunsInHostMode;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = (string) config('tenancy.base_domain');

        $this->provisionSecondTenant();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreTenancyResolution();
    }

    public function test_the_mail_link_points_at_the_tenant_host(): void
    {
        [, $url] = $this->sendActivationMail('main');

        $this->assertStringStartsWith($this->expectedOrigin('main') . '/activate?token=', $url);
    }

    public function test_the_link_names_the_host_of_the_users_tenant_not_the_default_one(): void
    {
        [, $url] = $this->sendActivationMail('second');

        $this->assertStringStartsWith($this->expectedOrigin('second') . '/activate?token=', $url);
    }

    public function test_the_link_works_end_to_end_on_the_tenant_host(): void
    {
        [$user, $url] = $this->sendActivationMail('main');

        // The link carries the scheme of APP_URL; the test client speaks http, the path is what is under test.
        $path = (string) parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $host = "http://main.{$this->base}";

        $this->get($host . $path)
            ->assertOk()
            ->assertViewIs('staff.activate');

        $this->post("{$host}/activate", [
            'token'                 => $query['token'],
            'password'              => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ])->assertRedirect("{$host}/admin");

        $this->assertSame(UserStatus::Active, $user->fresh()->status);
    }

    public function test_a_link_issued_before_a_rename_still_activates_on_the_new_host(): void
    {
        [$user, $url] = $this->sendActivationMail('main');
        $this->app->make(TenantRenamerInterface::class)->rename('00000000-0000-0000-0000-000000000001', 'main-new');

        $path = (string) parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        // The old host sends the link on to the new one with the token intact.
        $this->get("http://main.{$this->base}{$path}")
            ->assertStatus(302)
            ->assertRedirect($this->expectedOrigin('main-new') . $path);

        $host = "http://main-new.{$this->base}";

        $this->get($host . $path)->assertOk()->assertViewIs('staff.activate');
        $this->post("{$host}/activate", [
            'token'                 => $query['token'],
            'password'              => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ])->assertRedirect("{$host}/admin");

        $this->assertSame(UserStatus::Active, $user->fresh()->status);
    }

    public function test_base_domain_and_foreign_hosts_answer_404(): void
    {
        [, $url] = $this->sendActivationMail('main');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        foreach (["http://{$this->base}", "http://ghost.{$this->base}", 'http://example.com'] as $origin) {
            $this->get("{$origin}/activate?token={$query['token']}")->assertNotFound();
            $this->post("{$origin}/activate", [
                'token'                 => $query['token'],
                'password'              => 'a-long-password',
                'password_confirmation' => 'a-long-password',
            ])->assertNotFound();
        }
    }

    public function test_missing_token_shows_the_expired_page_on_the_tenant_host(): void
    {
        $this->get("http://main.{$this->base}/activate")
            ->assertOk()
            ->assertViewIs('staff.activation-expired');
    }

    public function test_a_token_of_one_tenant_is_not_valid_on_another(): void
    {
        if (! $this->onPostgres()) {
            $this->markTestSkipped('Two tenants with separate users need PostgreSQL schemas.');
        }

        [$user, $url] = $this->sendActivationMail('main');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        Auth::forgetGuards();

        $this->get("http://second.{$this->base}/activate?token={$query['token']}")
            ->assertOk()
            ->assertViewIs('staff.activation-expired');

        Auth::forgetGuards();

        $this->post("http://second.{$this->base}/activate", [
            'token'                 => $query['token'],
            'password'              => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ])->assertRedirect();

        $this->assertSame(UserStatus::Pending, $user->fresh()->status);
    }

    /**
     * Runs the job for the tenant and returns the pending user and the link of the mail it sent.
     *
     * @return array{0: User, 1: string}
     */
    /**
     * Scheme and port follow APP_URL, as `TenantHost::urlFor()` builds them: CI's .env (copied from
     * .env.example) carries a port, a local one may not.
     */
    private function expectedOrigin(string $slug): string
    {
        $appUrl = (string) config('app.url');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'https';
        $port   = parse_url($appUrl, PHP_URL_PORT);

        return "{$scheme}://{$slug}.{$this->base}" . (null === $port ? '' : ':' . $port);
    }

    private function sendActivationMail(string $slug): array
    {
        Mail::fake();

        $tenantId = 'main' === $slug ? '00000000-0000-0000-0000-000000000001' : '00000000-0000-0000-0000-000000000002';

        $tenant = $this->app->make(TenantRepositoryInterface::class)->getById($tenantId);

        [$user, $plain] = $this->app->make(TenantSwitcher::class)->runForTenant($tenant, function (): array {
            $user = User::factory()->create(['status' => UserStatus::Pending]);

            return [$user, $this->app->make(ActivationTokenService::class)->issue($user)];
        });

        (new SendActivationEmailJob($tenantId, (string) $user->getKey(), $plain))
            ->handle($this->app->make(TenantRepositoryInterface::class), $this->app->make(TenantSwitcher::class));

        $url = null;
        Mail::assertSent(ActivationMail::class, function (ActivationMail $mail) use (&$url): bool {
            $url = $mail->activationUrl;

            return true;
        });

        return [$user, (string) $url];
    }
}
