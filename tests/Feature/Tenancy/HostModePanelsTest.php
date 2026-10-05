<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Support\TenantHost;
use App\Filament\Resources\Assistants\AssistantResource;
use Database\Seeders\TenantAclSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Symfony\Component\HttpFoundation\Cookie;
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
    private const string SECOND_SCHEMA = 'second_schema';

    private string $base;

    /**
     * @var array{env: mixed, server: mixed, getenv: string|false}|null
     */
    private ?array $previousResolution = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = (string) config('tenancy.base_domain');

        $this->provisionSecondTenant();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $previous = $this->previousResolution;

        Env::getRepository()->clear('TENANCY_RESOLUTION');
        $this->restoreVariable($_ENV, $previous['env'] ?? null);
        $this->restoreVariable($_SERVER, $previous['server'] ?? null);
        putenv(false === ($previous['getenv'] ?? false) ? 'TENANCY_RESOLUTION' : 'TENANCY_RESOLUTION=' . $previous['getenv']);
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

    protected function refreshApplication(): void
    {
        $this->previousResolution ??= [
            'env'    => $_ENV['TENANCY_RESOLUTION'] ?? null,
            'server' => $_SERVER['TENANCY_RESOLUTION'] ?? null,
            'getenv' => getenv('TENANCY_RESOLUTION'),
        ];

        // Forget the value the loader wrote on a previous boot, so the one below counts
        // as externally defined and wins over .env.
        Env::getRepository()->clear('TENANCY_RESOLUTION');
        $_ENV['TENANCY_RESOLUTION'] = $_SERVER['TENANCY_RESOLUTION'] = 'host';
        putenv('TENANCY_RESOLUTION=host');

        parent::refreshApplication();
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private function restoreVariable(array &$bag, mixed $value): void
    {
        if (null === $value) {
            unset($bag['TENANCY_RESOLUTION']);

            return;
        }

        $bag['TENANCY_RESOLUTION'] = $value;
    }
    private function provisionSecondTenant(): void
    {
        if ($this->onPostgres()) {
            $this->createSecondSchema();
        }

        DB::connection('landlord')->table('tenants')->insert([
            'id'          => '00000000-0000-0000-0000-000000000002',
            'slug'        => 'second',
            'schema_name' => self::SECOND_SCHEMA,
            'status'      => 'active',
            'config'      => '{}',
        ]);
    }

    /**
     * A schema of its own with the tenant tables, so users really differ between the tenants.
     *
     * TenantProvisioningService is not used on purpose: in tests the landlord is a separate
     * database (CI uses `fapost_landlord_test`) and FeatureTestCase wraps the `landlord`
     * connection in a transaction, so a schema the service creates through landlord is
     * invisible to the tenant connection (the same goes for createSchema()). The schema is created on the tenant connection
     * and entered through the production switcher, which keeps connection config and
     * session in sync.
     */
    private function createSecondSchema(): void
    {
        $tenant   = new Tenant(['slug' => 'second', 'schema_name' => self::SECOND_SCHEMA]);
        $database = $this->app->make(TenantDatabaseManagerInterface::class);

        DB::connection((string) config('tenancy.tenant_connection'))
            ->statement('CREATE SCHEMA ' . self::SECOND_SCHEMA);
        $database->switchTo($tenant);

        try {
            foreach (['database/settings', 'database/migrations/tenant'] as $path) {
                $this->artisan('migrate', ['--path' => $path, '--force' => true])->assertExitCode(0);
            }

            $this->seed(TenantAclSeeder::class);
        } finally {
            $database->restore();
        }
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

    private function onPostgres(): bool
    {
        return 'pgsql' === config('database.connections.' . config('tenancy.tenant_connection') . '.driver');
    }
}
