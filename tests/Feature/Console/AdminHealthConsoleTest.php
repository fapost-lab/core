<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Health\Contracts\HealthProbe;
use App\Domains\Health\Services\HealthService;
use App\Domains\Health\ValueObjects\ProbeResult;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;

/**
 * The health page of the admin panel: what it draws from the health service, how each state reaches the browser, and
 * who may open it. The probes are replaced by fixed answers; the probes themselves are tested with the service.
 */
final class AdminHealthConsoleTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_the_first_visit_opens_at_once_without_asking_the_probes(): void
    {
        $this->answers([$this->probe('reverb', ProbeResult::up('reverb', 'ok'))]);

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/AdminHealth/Index')
                ->where('navigation.mode', 'admin')
                ->where('pollSeconds', 15)
                ->missing('report')
                ->etc());
    }

    public function test_in_single_mode_the_page_is_the_installations_with_the_queue_card(): void
    {
        $this->answers([$this->probe('queues', ProbeResult::up('queues', 'Horizon is running.', ['masters' => 1]))]);

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('scope', 'installation')->etc());

        $this->assertSame(['reverb', 'gateway', 'queues'], array_keys($this->report()));
        $this->assertSame('up', $this->report()['queues']['state']);
    }

    public function test_a_reload_carries_reverb_and_the_gateway_as_the_probes_reported_them(): void
    {
        $this->answers([
            $this->probe('reverb', ProbeResult::up('reverb', 'Reverb answered at reverb:8080.', [
                'address'       => 'reverb:8080',
                'connections'   => 3,
                'channels'      => 2,
                'channel_names' => ['private-tenant.secret-id.flow', 'private-other'],
            ])),
            $this->probe('gateway', ProbeResult::up('gateway', 'The gateway answered.', [
                'url'   => 'http://gateway:8080/healthz',
                'specs' => ['telegram' => 'matches', 'viber' => 'differs'],
                'drift' => ['telegram' => 2, 'viber' => 1],
            ])),
        ]);

        $report = $this->report();

        $this->assertSame('up', $report['reverb']['state']);
        $this->assertSame(3, $report['reverb']['connections']);
        $this->assertSame(2, $report['reverb']['channels']);
        $this->assertSame('reverb:8080', $report['reverb']['address']);
        $this->assertArrayNotHasKey('channel_names', $report['reverb']);
        $this->assertStringNotContainsString('secret-id', json_encode($report, JSON_THROW_ON_ERROR));
        $this->assertSame('up', $report['gateway']['state']);
        $this->assertSame('http://gateway:8080/healthz', $report['gateway']['url']);
        $this->assertSame([
            ['platform' => 'telegram', 'status' => 'matches'],
            ['platform' => 'viber', 'status' => 'differs'],
        ], $report['gateway']['specs']);
        $this->assertSame(3, $report['gateway']['drift']['total']);
        $this->assertSame(['platform' => 'telegram', 'count' => 2], $report['gateway']['drift']['platforms'][0]);
    }

    public function test_down_unknown_and_switched_off_each_keep_their_own_state_and_reason(): void
    {
        $this->answers([
            $this->probe('reverb', ProbeResult::unknown('reverb', 'Reverb could not be reached.', ['address' => 'reverb:8080'])),
            $this->probe('gateway', ProbeResult::disabled('gateway', 'The ingress driver is "laravel".', ['driver' => 'laravel'])),
        ]);

        $report = $this->report();

        $this->assertSame('unknown', $report['reverb']['state']);
        $this->assertSame('Reverb could not be reached.', $report['reverb']['reason']);
        $this->assertNull($report['reverb']['connections']);
        $this->assertNull($report['reverb']['channels']);
        $this->assertSame('disabled', $report['gateway']['state']);
        $this->assertNull($report['gateway']['specs']);
        $this->assertNull($report['gateway']['drift']);

        $this->answers([$this->probe('reverb', ProbeResult::down('reverb', 'Reverb answered 503.')), $this->probe('gateway', ProbeResult::down('gateway', 'The gateway answered 502.'))]);

        $report = $this->report();

        $this->assertSame('down', $report['reverb']['state']);
        $this->assertSame('down', $report['gateway']['state']);
    }

    public function test_credentials_and_tokens_in_an_address_never_reach_the_browser(): void
    {
        $url = 'https://user:hunter2@gateway.test:8443/healthz?token=abc123';
        $this->answers([
            $this->probe('gateway', ProbeResult::up('gateway', "The gateway answered at {$url}.", ['url' => $url])),
            $this->probe('reverb', ProbeResult::unknown('reverb', "Could not reach {$url}.")),
        ]);

        $report = $this->report();
        $json   = json_encode($report, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('hunter2', $json);
        $this->assertStringNotContainsString('abc123', $json);
        $this->assertSame('https://gateway.test:8443/healthz', $report['gateway']['url']);
        $this->assertSame('The gateway answered at https://gateway.test:8443/healthz.', $report['gateway']['reason']);
        $this->assertSame('Could not reach https://gateway.test:8443/healthz.', $report['reverb']['reason']);
    }

    public function test_a_service_the_report_does_not_mention_is_unknown_never_healthy(): void
    {
        $this->answers([]);

        $report = $this->report();

        $this->assertSame('unknown', $report['reverb']['state']);
        $this->assertNull($report['reverb']['checkedAt']);
        $this->assertNull($report['reverb']['reason']);
        $this->assertSame('unknown', $report['gateway']['state']);
    }

    public function test_the_queue_card_carries_the_figures_the_probe_measured(): void
    {
        $this->answers([
            $this->probe('queues', ProbeResult::up('queues', 'Horizon is running (1 instance).', [
                'masters'               => 1,
                'failed'                => 5,
                'failed_window_minutes' => 10080,
                'window_started_at'     => '2026-10-26T10:00:00+00:00',
                'queues'                => [
                    ['name' => 'flow.execution', 'connection' => 'redis', 'wait_seconds' => 70, 'wait_threshold' => 60, 'throughput' => 10, 'jobs_per_minute' => 2.5],
                    ['name' => 'default', 'connection' => 'redis', 'wait_seconds' => null, 'wait_threshold' => 60, 'throughput' => 0, 'jobs_per_minute' => null],
                    ['name' => 'low', 'connection' => 'redis', 'wait_seconds' => 3, 'wait_threshold' => 60, 'throughput' => 3, 'jobs_per_minute' => 3.0],
                ],
            ])),
        ]);

        $queues = $this->report()['queues'];

        $this->assertSame('up', $queues['state']);
        $this->assertSame(5, $queues['failed']);
        $this->assertSame(168, $queues['failedWindowHours']);
        $this->assertSame(1, $queues['supervisors']);
        $this->assertSame('2026-10-26T10:00:00+00:00', $queues['windowStartedAt']);
        $this->assertSame(['name' => 'flow.execution', 'waitSeconds' => 70, 'waitThreshold' => 60, 'slow' => true, 'jobsPerMinute' => 2.5], $queues['queues'][0]);
        $this->assertSame(['name' => 'default', 'waitSeconds' => null, 'waitThreshold' => 60, 'slow' => false, 'jobsPerMinute' => null], $queues['queues'][1]);
        $this->assertFalse($queues['queues'][2]['slow']);
        $this->assertEquals(3.0, $queues['queues'][2]['jobsPerMinute']);
    }

    public function test_a_queue_card_without_figures_and_a_missing_one_read_as_unknown_not_empty(): void
    {
        $this->answers([$this->probe('queues', ProbeResult::disabled('queues', 'Queues run on the "database" connection.', ['connection' => 'database']))]);

        $queues = $this->report()['queues'];

        $this->assertSame('disabled', $queues['state']);
        $this->assertNull($queues['queues']);
        $this->assertNull($queues['failed']);
        $this->assertNull($queues['supervisors']);

        $this->answers([]);

        $queues = $this->report()['queues'];

        $this->assertSame('unknown', $queues['state']);
        $this->assertNull($queues['checkedAt']);
        $this->assertNull($queues['queues']);
    }

    public function test_the_admin_menu_links_to_the_page_in_the_system_group(): void
    {
        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('navigation.groups', fn ($groups): bool => collect($groups)
                    ->where('label', __('console.navigation.system'))
                    ->flatMap(static fn (array $group): array => $group['items'])
                    ->contains(static fn (array $item): bool => 'console.admin.health.index' === $item['key']
                        && '/admin/health' === $item['href']
                        && 'heart-pulse' === $item['icon']))
                ->etc());
    }

    public function test_the_right_to_view_the_system_opens_it_and_other_rights_do_not(): void
    {
        $allowed = User::factory()->create();
        $allowed->givePermissionTo(Permission::ViewSystem->value);
        $this->actingAs($allowed)->get($this->url())->assertOk();

        $denied = User::factory()->create();
        $denied->givePermissionTo(Permission::ManageSettings->value);
        $this->actingAs($denied)->get($this->url())->assertForbidden();
        $this->actingAs($denied)->get($this->url(), $this->reloadHeaders())->assertForbidden();
    }

    public function test_a_user_without_the_right_does_not_see_the_menu_item(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageSettings->value);

        $this->actingAs($user)
            ->get($this->panelUrl('/admin/usage'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('navigation.groups', fn ($groups): bool => ! collect($groups)
                    ->flatMap(static fn (array $group): array => $group['items'])
                    ->contains(static fn (array $item): bool => 'console.admin.health.index' === $item['key']))
                ->etc());
    }

    public function test_a_visitor_is_sent_to_the_sign_in(): void
    {
        $this->get($this->url())->assertRedirect(route('console.auth.login'));
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
     * The `report` prop a polling reload receives.
     *
     * @return array<string, array<string, mixed>>
     */
    private function report(): array
    {
        $response = $this->actingAs($this->admin())->get($this->url(), $this->reloadHeaders())->assertOk();

        return $response->json('props.report');
    }

    /**
     * What the page's polling sends: a partial reload of the `report` prop.
     *
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
        return $this->panelUrl('/admin/health');
    }
}
