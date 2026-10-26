<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Health;

use App\Domains\Health\Contracts\HealthProbe;
use App\Domains\Health\Probes\GatewayProbe;
use App\Domains\Health\Probes\QueueProbe;
use App\Domains\Health\Probes\ReverbProbe;
use App\Domains\Health\Probes\WebhookRegistryProbe;
use App\Domains\Health\Providers\HealthServiceProvider;
use App\Domains\Health\Services\CoreInstallationHealth;
use App\Domains\Health\Services\HealthService;
use App\Domains\Health\ValueObjects\ProbeResult;
use DateTimeImmutable;
use Fapost\Foundation\Health\Contracts\InstallationHealthInterface;
use Fapost\Foundation\Health\DTO\InstallationHealthReport;
use Fapost\Foundation\Health\DTO\ServiceHealth;
use Fapost\Foundation\Health\Enums\HealthState;
use Fapost\Foundation\Health\Enums\HealthUnit;
use Fapost\Foundation\Health\Enums\MonitoredService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use RuntimeException;
use Tests\TestCase;

final class CoreInstallationHealthTest extends TestCase
{
    public function test_the_container_answers_the_contract_with_cores_default(): void
    {
        $this->assertInstanceOf(CoreInstallationHealth::class, $this->app->make(InstallationHealthInterface::class));
    }

    public function test_an_operator_layers_own_binding_is_kept(): void
    {
        $own = new class () implements InstallationHealthInterface {
            public function report(): InstallationHealthReport
            {
                return new InstallationHealthReport([], new DateTimeImmutable());
            }
        };
        $this->app->instance(InstallationHealthInterface::class, $own);

        $this->app->register(HealthServiceProvider::class, force: true);

        $this->assertSame($own, $this->app->make(InstallationHealthInterface::class));
    }

    public function test_the_container_report_covers_every_probe_without_a_tenant(): void
    {
        $report = $this->app->make(InstallationHealthInterface::class)->report();

        $this->assertSame(
            [MonitoredService::Reverb, MonitoredService::Gateway, MonitoredService::WebhookRegistry, MonitoredService::Queues],
            array_map(static fn (ServiceHealth $service): MonitoredService => $service->service, $report->services),
        );
    }

    public function test_reverb_reports_counts_and_never_channel_names(): void
    {
        $reverb = $this->report(ProbeResult::up(ReverbProbe::KEY, 'Reverb answered at reverb:8080.', [
            'address'       => 'reverb:8080',
            'connections'   => 3,
            'channels'      => 2,
            'channel_names' => ['private-tenant.01JTENANTSECRETID.inbox', 'presence-tenant.01JOTHER'],
        ]))->service(MonitoredService::Reverb);

        $this->assertNotNull($reverb);
        $this->assertSame(HealthState::Up, $reverb->state);
        $this->assertSame('reverb:8080', $reverb->endpoint);
        $this->assertSame(3, $reverb->metric('connections')?->value);
        $this->assertSame(2, $reverb->metric('channels')?->value);
        $this->assertStringNotContainsString('01JTENANTSECRETID', serialize($reverb));
    }

    public function test_gateway_credentials_and_errors_do_not_leave_core(): void
    {
        $url     = 'https://admin:hunter2@gateway.example:8443/healthz?token=s3cr3t';
        $gateway = $this->report(ProbeResult::unknown(GatewayProbe::KEY, "Could not reach {$url}.", [
            'url'    => $url,
            'status' => null,
            'error'  => "cURL error 7: Failed to connect to {$url}",
            'driver' => 'gateway',
            'specs'  => ['telegram' => 'matches', 'viber' => 'differs'],
            'drift'  => ['telegram' => 4],
        ]))->service(MonitoredService::Gateway);

        $this->assertNotNull($gateway);
        $this->assertSame(HealthState::Unknown, $gateway->state);
        $this->assertSame('Could not reach https://gateway.example:8443/healthz.', $gateway->reason);
        $this->assertSame('https://gateway.example:8443/healthz', $gateway->endpoint);
        $this->assertSame('differs', $gateway->metric('spec_status', 'viber')?->value);
        $this->assertSame(HealthUnit::Text, $gateway->metric('spec_status', 'viber')?->unit);
        $this->assertSame(4, $gateway->metric('drifted_channels', 'telegram')?->value);

        $dump = serialize($gateway);
        $this->assertStringNotContainsString('hunter2', $dump);
        $this->assertStringNotContainsString('s3cr3t', $dump);
        $this->assertStringNotContainsString('cURL error', $dump);
    }

    public function test_the_registry_reports_its_drift_counts(): void
    {
        $registry = $this->report(ProbeResult::down(WebhookRegistryProbe::KEY, 'Drift.', [
            'missing'  => 1,
            'stale'    => 0,
            'orphaned' => 2,
        ]))->service(MonitoredService::WebhookRegistry);

        $this->assertNotNull($registry);
        $this->assertSame(HealthState::Down, $registry->state);
        $this->assertNull($registry->endpoint);
        $this->assertSame([1, 0, 2], [
            $registry->metric('missing')?->value,
            $registry->metric('stale')?->value,
            $registry->metric('orphaned')?->value,
        ]);
    }

    public function test_queues_report_wait_and_throughput_per_queue_and_failed_jobs(): void
    {
        $queues = $this->report(ProbeResult::up(QueueProbe::KEY, 'Horizon is running (1 instance).', [
            'masters' => ['host-1' => 'running'],
            'failed'  => 5,
            'queues'  => [
                ['name' => 'flow.execution', 'connection' => 'redis', 'wait_seconds' => 12, 'wait_threshold' => 60, 'throughput' => 300, 'jobs_per_minute' => 7.5],
                ['name' => 'broadcasts', 'connection' => 'redis', 'wait_seconds' => null, 'wait_threshold' => 60, 'throughput' => 0, 'jobs_per_minute' => null],
            ],
        ]))->service(MonitoredService::Queues);

        $this->assertNotNull($queues);
        $this->assertSame(12, $queues->metric('wait_seconds', 'flow.execution')?->value);
        $this->assertSame(HealthUnit::Seconds, $queues->metric('wait_seconds', 'flow.execution')?->unit);
        $this->assertSame(7.5, $queues->metric('throughput', 'flow.execution')?->value);
        $this->assertSame(HealthUnit::PerMinute, $queues->metric('throughput', 'flow.execution')?->unit);
        $this->assertNull($queues->metric('wait_seconds', 'broadcasts')?->value, 'An unmeasured wait stays null, never 0.');
        $this->assertNotNull($queues->metric('wait_seconds', 'broadcasts'));
        $this->assertSame(5, $queues->metric('failed_jobs')?->value);
    }

    public function test_a_disabled_service_stays_disabled_with_no_metrics(): void
    {
        $reverb = $this->report(ProbeResult::disabled(ReverbProbe::KEY, 'Broadcasting is not Reverb.', ['driver' => 'log']))
            ->service(MonitoredService::Reverb);

        $this->assertSame(HealthState::Disabled, $reverb?->state);
        $this->assertSame([], $reverb?->metrics);
    }

    public function test_a_probe_the_contract_does_not_know_is_left_out(): void
    {
        $report = $this->report(ProbeResult::up('something_new', 'Fine.'));

        $this->assertSame([], $report->services);
    }

    public function test_a_probe_that_throws_is_unknown(): void
    {
        $report = $this->reportOf(new class () implements HealthProbe {
            public function key(): string
            {
                return ReverbProbe::KEY;
            }

            public function cacheTtl(): int
            {
                return 5;
            }

            public function check(): ProbeResult
            {
                throw new RuntimeException('boom');
            }
        });

        $this->assertSame(HealthState::Unknown, $report->service(MonitoredService::Reverb)?->state);
    }

    public function test_an_unreadable_report_is_every_service_unknown_not_an_exception(): void
    {
        $health = new HealthService([new class () implements HealthProbe {
            public function key(): string
            {
                throw new RuntimeException('the key itself fails');
            }

            public function cacheTtl(): int
            {
                return 5;
            }

            public function check(): ProbeResult
            {
                return ProbeResult::up('x', 'Fine.');
            }
        }], new Repository(new ArrayStore()));

        $report = (new CoreInstallationHealth($health))->report();

        $this->assertCount(4, $report->services);
        foreach ($report->services as $service) {
            $this->assertSame(HealthState::Unknown, $service->state);
        }
    }

    private function report(ProbeResult $result): InstallationHealthReport
    {
        return $this->reportOf(new readonly class ($result) implements HealthProbe {
            public function __construct(private ProbeResult $result)
            {
            }

            public function key(): string
            {
                return $this->result->probe;
            }

            public function cacheTtl(): int
            {
                return 5;
            }

            public function check(): ProbeResult
            {
                return $this->result;
            }
        });
    }

    private function reportOf(HealthProbe $probe): InstallationHealthReport
    {
        return (new CoreInstallationHealth(new HealthService([$probe], new Repository(new ArrayStore()))))->report();
    }
}
