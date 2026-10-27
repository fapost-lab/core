<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Health\Contracts\HealthProbe;
use App\Domains\Health\Probes\QueueProbe;
use App\Domains\Health\Services\HealthService;
use App\Domains\Health\ValueObjects\ProbeResult;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\WebhookRegistryWriter;
use App\Domains\Webhook\Enums\IngressDriver;
use App\Http\Middleware\RefuseWritesWhenTenantStopped;
use App\Http\Shell\NavigationBuilder;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Concerns\RunsInHostMode;
use Tests\Feature\FeatureTestCase;

/**
 * In `host` mode the health page shows the current tenant's slice only: its live channels and its channels' ingress.
 * The figures that belong to the whole installation (connections, addresses, specifications, reasons, the queues) and
 * everything of another tenant must never reach the browser.
 */
final class AdminHealthHostModeTest extends FeatureTestCase
{
    use RunsInHostMode;

    private const string SECOND_ID = '00000000-0000-0000-0000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();

        $this->provisionSecondTenant();
        $this->seed(TenantAclSeeder::class);

        $this->withoutMiddleware(RefuseWritesWhenTenantStopped::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreTenancyResolution();
    }

    public function test_the_page_opens_for_an_administrator_with_the_tenant_scope(): void
    {
        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/AdminHealth/Index')
                ->where('scope', 'tenant')
                ->where('pollSeconds', 15)
                ->missing('report')
                ->etc());
    }

    public function test_the_menu_offers_it_to_a_user_with_the_right_and_a_user_without_it_gets_403(): void
    {
        $this->assertStringContainsString('console.admin.health.index', json_encode(app(NavigationBuilder::class)->admin($this->admin()), JSON_THROW_ON_ERROR));

        $plain = User::factory()->create();

        $this->assertStringNotContainsString('console.admin.health.index', json_encode(app(NavigationBuilder::class)->admin($plain), JSON_THROW_ON_ERROR));
        $this->actingAs($plain)->get($this->url())->assertForbidden();
        $this->actingAs($plain)->get($this->url(), $this->reloadHeaders())->assertForbidden();
    }

    public function test_the_slice_carries_reverb_and_the_gateway_only_with_the_tenants_own_figures(): void
    {
        $mainId = $this->mainTenantId();

        $this->answers([
            $this->probe('reverb', ProbeResult::up('reverb', 'Reverb answered at reverb-host:8080.', [
                'address'       => 'reverb-host:8080',
                'connections'   => 7,
                'channels'      => 4,
                'channel_names' => [
                    "private-tenant.{$mainId}.assistant.a.flow",
                    "private-tenant.{$mainId}.assistant.b.conversations",
                    'private-tenant.' . self::SECOND_ID . '.assistant.x.flow',
                    'private-other',
                ],
            ])),
            $this->probe('gateway', ProbeResult::up('gateway', 'Answered at http://gateway-host:8080/healthz.', [
                'url'   => 'http://gateway-host:8080/healthz',
                'specs' => ['telegram' => 'matches'],
                'drift' => ['telegram' => 99],
            ])),
            $this->probe(QueueProbe::KEY, ProbeResult::up(QueueProbe::KEY, 'Horizon is running.', [
                'masters' => 1,
                'failed'  => 5,
                'queues'  => [['name' => 'secret.queue.name', 'wait_seconds' => 1, 'wait_threshold' => 60, 'jobs_per_minute' => 1.0]],
            ])),
        ]);
        $this->driftOf(main: 2, second: 5);

        $report = $this->report();

        $this->assertSame(['reverb', 'gateway'], array_keys($report));

        $this->assertSame('up', $report['reverb']['state']);
        $this->assertSame(2, $report['reverb']['channels']);
        $this->assertNull($report['reverb']['connections']);
        $this->assertNull($report['reverb']['address']);
        $this->assertNull($report['reverb']['reason']);

        $this->assertSame('up', $report['gateway']['state']);
        $this->assertNull($report['gateway']['url']);
        $this->assertNull($report['gateway']['specs']);
        $this->assertNull($report['gateway']['reason']);
        $this->assertSame(2, $report['gateway']['drift']['total']);

        $json = json_encode($report, JSON_THROW_ON_ERROR);

        foreach ([self::SECOND_ID, 'reverb-host', 'gateway-host', 'secret.queue.name', 'channel_names', $mainId] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
    }

    public function test_unknown_and_disabled_states_reach_the_page_without_their_reasons(): void
    {
        $this->answers([
            $this->probe('reverb', ProbeResult::unknown('reverb', 'Could not reach reverb-host:8080 (REVERB_HOST).')),
            $this->probe('gateway', ProbeResult::disabled('gateway', 'The ingress driver is "laravel".')),
        ]);

        $report = $this->report();

        $this->assertSame('unknown', $report['reverb']['state']);
        $this->assertNull($report['reverb']['reason']);
        $this->assertNull($report['reverb']['channels']);
        $this->assertSame('disabled', $report['gateway']['state']);
        $this->assertNull($report['gateway']['drift']);
        $this->assertStringNotContainsString('REVERB_HOST', json_encode($report, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<HealthProbe> $probes
     */
    private function answers(array $probes): void
    {
        $this->app->instance(HealthService::class, new HealthService($probes, new Repository(new ArrayStore())));
    }

    private function probe(string $key, ProbeResult $result): HealthProbe
    {
        return new class ($key, $result) implements HealthProbe {
            public function __construct(private readonly string $key, private readonly ProbeResult $result)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function cacheTtl(): int
            {
                return 0;
            }

            public function check(): ProbeResult
            {
                return $this->result;
            }
        };
    }

    /**
     * Drifted registry rows per tenant, on real landlord rows: the other tenant's must not count.
     */
    private function driftOf(int $main, int $second): void
    {
        config()->set('webhook.base_url', 'https://app.example.com');
        config()->set('webhook.ingress.gateway_url', 'https://webhook.example.com');
        config()->set('webhook.ingress.driver', IngressDriver::Gateway->value);

        Redis::shouldReceive('set')->andReturnTrue();

        $writer = $this->app->make(WebhookRegistryWriter::class);

        foreach ([[Tenant::query()->findOrFail($this->mainTenantId()), $main], [Tenant::query()->findOrFail(self::SECOND_ID), $second]] as [$tenant, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $writer->write(Str::random(48), $tenant, (string) Str::uuid(), (string) Str::uuid(), 'telegram', 'secret');
            }
        }
    }

    private function mainTenantId(): string
    {
        return (string) Tenant::query()->where('slug', config('tenancy.default_tenant_slug'))->firstOrFail()->getKey();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function report(): array
    {
        return $this->actingAs($this->admin())->get($this->url(), $this->reloadHeaders())->assertOk()->json('props.report');
    }

    /**
     * @return array<string, string>
     */
    private function reloadHeaders(): array
    {
        return [
            'X-Inertia'                   => 'true',
            'X-Inertia-Version'           => (string) Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'Console/AdminHealth/Index',
            'X-Inertia-Partial-Data'      => 'report',
        ];
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    private function url(): string
    {
        return 'http://main.' . config('tenancy.base_domain') . '/admin/health';
    }
}
