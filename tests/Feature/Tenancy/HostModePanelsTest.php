<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Support\TenantHost;
use App\Filament\Resources\Assistants\AssistantResource;
use Database\Seeders\TenantAclSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Feature\Concerns\RunsInHostMode;
use Tests\Feature\FeatureTestCase;

/**
 * The Filament panels in `host` mode: bound to no host, served on every tenant host.
 *
 * The panel domain is read once, when the panels are registered, so the mode has to be
 * in place before the application boots. It is set as a real process variable and the
 * application is rebuilt, as {@see \Tests\Unit\Domains\Tenancy\TenancyResolutionModeTest}
 * does; setting config after boot would leave the panels bound to the default tenant host.
 */
final class HostModePanelsTest extends FeatureTestCase
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

    public function test_panel_domain_is_unbound_in_host_mode_and_the_default_host_in_single_mode(): void
    {
        $this->assertNull(TenantHost::panelDomain());

        config(['tenancy.resolution' => 'single']);

        $this->assertSame(TenantHost::forDefaultTenant(), TenantHost::panelDomain());
        $this->assertNotNull(TenantHost::panelDomain());
    }

    public function test_panels_are_registered_without_a_domain(): void
    {
        $this->assertSame([], Filament::getPanel('admin')->getDomains());
        $this->assertSame([], Filament::getPanel('assistant')->getDomains());
    }

    public function test_panel_logins_are_served_on_every_tenant_host(): void
    {
        foreach (['main', 'second'] as $slug) {
            $this->get("http://{$slug}.{$this->base}/admin/login")->assertOk();
            $this->get("http://{$slug}.{$this->base}/assistant/login")->assertOk();
        }
    }

    public function test_panels_answer_404_on_the_base_domain_and_on_foreign_hosts(): void
    {
        foreach (['/admin/login', '/assistant/login', '/admin'] as $path) {
            $this->get("http://{$this->base}{$path}")->assertNotFound();
            $this->get("http://ghost.{$this->base}{$path}")->assertNotFound();
            $this->get("http://example.com{$path}")->assertNotFound();
        }
    }

    public function test_platform_subdomain_runs_without_a_tenant_and_serves_no_panels(): void
    {
        config(['tenancy.platform_subdomains' => ['ops']]);

        Route::middleware('web')->get('/_probe/platform', fn (): array => [
            'resolved' => $this->app->make(TenantContextInterface::class)->isResolved(),
        ]);

        $this->getJson("http://ops.{$this->base}/_probe/platform")
            ->assertOk()
            ->assertJsonPath('resolved', false);

        foreach (['/admin/login', '/assistant/login', '/admin'] as $path) {
            $this->get("http://ops.{$this->base}{$path}")->assertNotFound();
        }

        // An unlisted label stays a tenant host.
        $this->get("http://main.{$this->base}/admin/login")->assertOk();
    }

    public function test_guest_is_sent_to_the_login_of_the_host_they_came_to(): void
    {
        foreach (['main', 'second'] as $slug) {
            $this->get("http://{$slug}.{$this->base}/admin")
                ->assertRedirect("http://{$slug}.{$this->base}/admin/login");
        }
    }

    public function test_urls_built_during_a_request_follow_the_request_host(): void
    {
        Route::middleware(['web', 'tenant'])->get('/_probe/urls', fn (Request $request): array => [
            'route'    => route('filament.admin.auth.login'),
            'login'    => Filament::getPanel('assistant')->getLoginUrl(),
            'resource' => AssistantResource::getUrl(panel: 'admin'),
        ]);

        foreach (['main', 'second'] as $slug) {
            $this->getJson("http://{$slug}.{$this->base}/_probe/urls")
                ->assertOk()
                ->assertJsonPath('route', "http://{$slug}.{$this->base}/admin/login")
                ->assertJsonPath('login', "http://{$slug}.{$this->base}/assistant/login")
                ->assertJsonPath('resource', "http://{$slug}.{$this->base}/admin/assistants");
        }
    }

    public function test_livewire_update_for_a_panel_component_is_404_on_the_base_domain(): void
    {
        $payload = $this->loginComponentPayload();
        $path    = EndpointResolver::updatePath();

        $this->postJson("http://{$this->base}{$path}", $payload, ['X-Livewire' => 'true'])
            ->assertNotFound();
        $this->postJson("http://example.com{$path}", $payload, ['X-Livewire' => 'true'])
            ->assertNotFound();

        foreach (['main', 'second'] as $slug) {
            $this->assertNotSame(
                404,
                $this->postJson("http://{$slug}.{$this->base}{$path}", $payload, ['X-Livewire' => 'true'])
                    ->getStatusCode(),
            );
        }
    }

    public function test_session_of_one_tenant_host_does_not_open_the_panel_of_another(): void
    {
        if (! $this->onPostgres()) {
            $this->markTestSkipped('Two tenants with separate users need PostgreSQL schemas.');
        }

        $this->seed(TenantAclSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        Route::middleware(['web', 'tenant'])->get('/_probe/login', function () use ($user): array {
            Auth::login($user);

            return ['ok' => true];
        });

        $signIn = $this->get("http://main.{$this->base}/_probe/login")->assertOk();

        $cookie = collect($signIn->headers->getCookies())
            ->first(fn (Cookie $cookie): bool => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($cookie, 'Signing in must issue a session cookie.');
        $this->assertNull($cookie->getDomain(), 'The session cookie must stay host-only.');

        // The application is shared by the requests of one test; a real browser starts each
        // request with nobody signed in, and only the session cookie says otherwise.
        Auth::forgetGuards();

        // The cookie works on the host that issued it.
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->get("http://main.{$this->base}/admin")
            ->assertOk();

        Auth::forgetGuards();

        // Presented on another tenant host, the same session finds no such user there.
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->get("http://second.{$this->base}/admin")
            ->assertRedirect("http://second.{$this->base}/admin/login");
    }

    /**
     * A real login component update payload: the snapshot is taken from the page a tenant host serves.
     *
     * @return array{components: list<array<string, mixed>>}
     */
    private function loginComponentPayload(): array
    {
        $html = $this->get("http://main.{$this->base}/admin/login")->assertOk()->getContent();

        preg_match('/wire:snapshot="([^"]+)"/', (string) $html, $matches);
        $this->assertNotEmpty($matches, 'The login page must carry a Livewire snapshot.');

        return ['components' => [[
            'snapshot' => html_entity_decode($matches[1], ENT_QUOTES),
            'updates'  => [],
            'calls'    => [],
        ]]];
    }
}
