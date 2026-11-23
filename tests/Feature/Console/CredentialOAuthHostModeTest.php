<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Credentials\Models\Credential;
use App\Domains\Credentials\Types\OAuth2AuthorizationCodeType;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Database\Seeders\TenantAclSeeder;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Concerns\RunsInHostMode;
use Tests\Feature\FeatureTestCase;

/**
 * The consent callback in `host` mode: its URL is on the tenant's own host, and the base domain and hosts that name no
 * tenant answer 404.
 */
final class CredentialOAuthHostModeTest extends FeatureTestCase
{
    use RunsInHostMode;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = (string) config('tenancy.base_domain');
        $this->provisionSecondTenant();
        $this->seed(TenantAclSeeder::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreTenancyResolution();
    }

    public function test_the_callback_url_is_on_the_tenants_host_and_the_connect_uses_it(): void
    {
        $main = Tenant::query()->where('slug', 'main')->firstOrFail();

        [$admin, $credential] = $this->app->make(TenantSwitcher::class)->runForTenant($main, static function () use ($main): array {
            $admin = User::factory()->create();
            $admin->assignRole(RoleEnum::Admin->value);

            $credential = Credential::query()->create([
                'tenant_id'         => $main->getId(),
                'name'              => 'Google',
                'type'              => OAuth2AuthorizationCodeType::ID,
                'settings'          => ['authorize_url' => 'https://login.example.net/authorize', 'token_url' => 'https://auth.example.com/token', 'client_id' => 'client-1', 'client_auth' => 'basic'],
                'allowed_hosts'     => ['auth.example.com'],
                'secret'            => ['client_secret' => 'client-secret-value'],
                'secret_changed_at' => now(),
            ]);

            return [$admin, $credential];
        });

        $callback = "http://main.{$this->base}/admin/credentials/oauth/callback";

        $this->actingAs($admin)
            ->get("http://main.{$this->base}/admin/credentials/{$credential->id}/edit")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('oauth.callback_url', $callback)->etc());

        $location = (string) $this->post("http://main.{$this->base}/admin/credentials/{$credential->id}/oauth/connect")->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame($callback, $query['redirect_uri'] ?? null);
    }

    public function test_the_base_domain_and_hosts_that_name_no_tenant_answer_404(): void
    {
        foreach (["{$this->base}", "ghost.{$this->base}", 'example.com'] as $host) {
            $this->get("http://{$host}/admin/credentials/oauth/callback?state=x&code=y")->assertNotFound();
        }
    }
}
