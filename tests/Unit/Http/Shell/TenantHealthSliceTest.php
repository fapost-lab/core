<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Shell;

use App\Domains\Health\Enums\HealthState;
use App\Domains\Health\Probes\QueueProbe;
use App\Domains\Health\ValueObjects\HealthReport;
use App\Domains\Health\ValueObjects\ProbeResult;
use App\Domains\Tenancy\Contracts\WebhookRegistryReaderInterface;
use App\Http\Shell\TenantHealthSlice;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * The narrowing of the installation's health report to one tenant (host mode): what stays, what is counted by the
 * tenant's id, and everything that must be gone.
 */
final class TenantHealthSliceTest extends TestCase
{
    private const string TENANT = 'abc';

    public function test_reverb_keeps_only_the_number_of_the_tenants_channels(): void
    {
        $slice = $this->slice($this->report(reverb: ProbeResult::up('reverb', 'Reverb answered at reverb:8080.', [
            'address'       => 'reverb:8080',
            'connections'   => 9,
            'channels'      => 6,
            'channel_names' => [
                'private-tenant.abc.assistant.1.flow',
                'presence-tenant.abc.room',
                'private-encrypted-tenant.abc.secret',
                'tenant.abc.bare',
                'private-tenant.abcd.assistant.1.flow',
                'private-tenant.other.assistant.1.flow',
                'private-private-tenant.abc.twice',
                'private-other',
            ],
        ])));

        $reverb = $slice->get('reverb');

        $this->assertNotNull($reverb);
        $this->assertSame(HealthState::Up, $reverb->state);
        $this->assertSame('', $reverb->reason);
        $this->assertSame(['channels' => 3], $reverb->details);
    }

    public function test_a_tenant_id_that_starts_another_ones_id_does_not_match_it(): void
    {
        $report = $this->report(reverb: ProbeResult::up('reverb', 'ok', ['channel_names' => ['private-tenant.abcd.x', 'private-tenant.abc.x']]));

        $this->assertSame(['channels' => 1], $this->slice($report, 'abc')->get('reverb')?->details);
        $this->assertSame(['channels' => 1], $this->slice($report, 'abcd')->get('reverb')?->details);
    }

    public function test_reverb_without_channel_names_has_no_channel_figure(): void
    {
        $reverb = $this->slice($this->report(reverb: ProbeResult::unknown('reverb', 'Could not reach reverb:8080.', ['address' => 'reverb:8080'])))->get('reverb');

        $this->assertSame(HealthState::Unknown, $reverb?->state);
        $this->assertSame('', $reverb->reason);
        $this->assertSame([], $reverb->details);
    }

    public function test_the_gateway_keeps_the_tenants_drift_and_nothing_of_the_installation(): void
    {
        $reader = $this->reader();
        $reader->shouldReceive('countIngressDrift')->with(Mockery::any(), Mockery::any(), self::TENANT)->andReturn(2);

        $gateway = $this->slice($this->report(gateway: ProbeResult::up('gateway', 'Answered at http://gateway:8080/healthz.', [
            'url'   => 'http://gateway:8080/healthz',
            'specs' => ['telegram' => 'matches'],
            'drift' => ['telegram' => 40],
        ])))->get('gateway');

        $this->assertSame(HealthState::Up, $gateway?->state);
        $this->assertSame('', $gateway->reason);
        $this->assertSame(['drift'], array_keys($gateway->details));
        $this->assertNotEmpty($gateway->details['drift']);
        $this->assertSame(2, array_values($gateway->details['drift'])[0]);
        $this->assertStringNotContainsString('gateway:8080', json_encode($gateway->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_a_disabled_gateway_has_no_drift_and_is_not_asked(): void
    {
        $this->reader()->shouldNotReceive('countIngressDrift');

        $gateway = $this->slice($this->report(gateway: ProbeResult::disabled('gateway', 'The ingress driver is "laravel".', ['driver' => 'laravel'])))->get('gateway');

        $this->assertSame(HealthState::Disabled, $gateway?->state);
        $this->assertSame([], $gateway->details);
    }

    public function test_a_drift_that_cannot_be_counted_is_null(): void
    {
        $this->reader()->shouldReceive('countIngressDrift')->andThrow(new RuntimeException('landlord is down'));

        $gateway = $this->slice($this->report(gateway: ProbeResult::up('gateway', 'ok')))->get('gateway');

        $this->assertSame(HealthState::Up, $gateway?->state);
        $this->assertSame(['drift' => null], $gateway->details);
    }

    public function test_queues_the_registry_and_anything_else_are_dropped(): void
    {
        $this->reader()->shouldReceive('countIngressDrift')->andReturn(0);

        $slice = $this->slice(new HealthReport([
            'reverb'           => ProbeResult::up('reverb', 'ok'),
            'gateway'          => ProbeResult::up('gateway', 'ok'),
            QueueProbe::KEY    => ProbeResult::up(QueueProbe::KEY, 'Horizon is running.', ['queues' => [['name' => 'flow.execution']]]),
            'webhook_registry' => ProbeResult::up('webhook_registry', 'ok'),
            'extra'            => ProbeResult::up('extra', 'ok'),
        ]));

        $this->assertSame(['reverb', 'gateway'], array_keys($slice->results));
        $this->assertStringNotContainsString('flow.execution', json_encode($slice->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_a_probe_missing_from_the_report_stays_missing(): void
    {
        $this->reader()->shouldReceive('countIngressDrift')->andReturn(0);

        $slice = $this->slice(new HealthReport([]));

        $this->assertNull($slice->get('reverb'));
        $this->assertNull($slice->get('gateway'));
    }

    private function reader(): Mockery\MockInterface
    {
        $reader = Mockery::mock(WebhookRegistryReaderInterface::class);
        $this->app->instance(WebhookRegistryReaderInterface::class, $reader);

        return $reader;
    }

    private function slice(HealthReport $report, string $tenantId = self::TENANT): HealthReport
    {
        return $this->app->make(TenantHealthSlice::class)->of($report, $tenantId);
    }

    private function report(?ProbeResult $reverb = null, ?ProbeResult $gateway = null): HealthReport
    {
        return new HealthReport(array_filter(['reverb' => $reverb, 'gateway' => $gateway]));
    }
}
