<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Support\TenantHost;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Concerns\RunsInHostMode;

/**
 * The Inertia sign-in in `host` mode: {@see \Tests\Feature\Tenancy\HostModePanelsTest} with `UI_INERTIA` on.
 *
 * The routes carry no domain in this mode, so `TenancyMiddleware` is the only host boundary: a tenant host reaches
 * the sign-in, the base domain, a foreign host and a platform subdomain get 404.
 */
final class ConsoleHostModeTest extends InertiaConsoleTestCase
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

    public function test_the_routes_carry_no_domain(): void
    {
        $this->assertNull(TenantHost::panelDomain());
        $this->assertNull(Route::getRoutes()->getByName('filament.admin.auth.login')?->getDomain());
    }

    public function test_the_login_is_an_inertia_page_on_every_tenant_host(): void
    {
        foreach (['main', 'second'] as $slug) {
            $this->get("http://{$slug}.{$this->base}/admin/login")
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('Auth/Login')
                    ->where('action', "http://{$slug}.{$this->base}/admin/login")
                    ->etc());

            $this->get("http://{$slug}.{$this->base}/assistant/login")
                ->assertRedirect("http://{$slug}.{$this->base}/admin/login");
        }
    }

    public function test_the_base_domain_foreign_hosts_and_platform_subdomains_answer_404(): void
    {
        config(['tenancy.platform_subdomains' => ['ops']]);

        foreach (['/admin/login', '/assistant/login', '/admin'] as $path) {
            foreach (["{$this->base}", "ghost.{$this->base}", 'example.com', "ops.{$this->base}"] as $host) {
                $this->get("http://{$host}{$path}")->assertNotFound();
            }
        }

        foreach (["{$this->base}", "ghost.{$this->base}", 'example.com', "ops.{$this->base}"] as $host) {
            $this->post("http://{$host}/admin/login", ['email' => 'a@example.com', 'password' => 'x'])->assertNotFound();
            $this->post("http://{$host}/console/logout")->assertNotFound();
        }
    }

    public function test_a_guest_is_sent_to_the_login_of_the_host_they_came_to(): void
    {
        foreach (['main', 'second'] as $slug) {
            $this->get("http://{$slug}.{$this->base}/admin")
                ->assertRedirect("http://{$slug}.{$this->base}/admin/login");
        }
    }

    public function test_signing_in_on_a_tenant_host_goes_to_that_hosts_dashboard(): void
    {
        $this->seed(TenantAclSeeder::class);

        $user = User::factory()->create(['password' => Hash::make('secret-password')]);
        $user->assignRole(RoleEnum::Admin->value);

        $this->post("http://main.{$this->base}/admin/login", ['email' => $user->email, 'password' => 'secret-password'], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', "http://main.{$this->base}/admin");
    }
}
