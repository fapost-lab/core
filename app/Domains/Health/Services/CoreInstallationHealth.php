<?php

declare(strict_types=1);

namespace App\Domains\Health\Services;

use App\Domains\Health\Probes\GatewayProbe;
use App\Domains\Health\Probes\QueueProbe;
use App\Domains\Health\Probes\ReverbProbe;
use App\Domains\Health\Probes\WebhookRegistryProbe;
use App\Domains\Health\Support\UrlScrubber;
use App\Domains\Health\ValueObjects\ProbeResult;
use DateTimeImmutable;
use Fapost\Foundation\Health\Contracts\InstallationHealthInterface;
use Fapost\Foundation\Health\DTO\HealthMetric;
use Fapost\Foundation\Health\DTO\InstallationHealthReport;
use Fapost\Foundation\Health\DTO\ServiceHealth;
use Fapost\Foundation\Health\Enums\HealthState;
use Fapost\Foundation\Health\Enums\HealthUnit;
use Fapost\Foundation\Health\Enums\MonitoredService;
use Throwable;

/**
 * Core's answer to the Foundation health contract: the {@see HealthService} report, translated for a package that
 * renders it (the monitoring package, an operator panel).
 *
 * Only an allow-list of figures leaves Core: counts and the names of queues and platforms. Channel names (they carry
 * tenant ids), exception texts and HTTP statuses stay behind, and every reason and endpoint goes through
 * {@see UrlScrubber}. It authorises nobody (the caller asks the operator access contract) and reads no tenant context.
 * It holds nothing but the scoped service it was made with; it is bound unshared, so each resolution gets the current
 * scope's service. Resolve it where it is used rather than keeping it in a singleton.
 */
final readonly class CoreInstallationHealth implements InstallationHealthInterface
{
    /** Probe key to the service the contract names it. A probe the contract does not know is left out. */
    private const array SERVICES = [
        ReverbProbe::KEY          => MonitoredService::Reverb,
        GatewayProbe::KEY         => MonitoredService::Gateway,
        WebhookRegistryProbe::KEY => MonitoredService::WebhookRegistry,
        QueueProbe::KEY           => MonitoredService::Queues,
    ];

    public function __construct(private HealthService $health)
    {
    }

    public function report(): InstallationHealthReport
    {
        try {
            $services = [];

            foreach ($this->health->report()->results as $key => $result) {
                $service = self::SERVICES[$key] ?? null;

                if (null !== $service) {
                    $services[] = $this->serviceHealthOrUnknown($service, $result);
                }
            }

            return new InstallationHealthReport($services, new DateTimeImmutable());
        } catch (Throwable $throwable) {
            report($throwable);

            return $this->unreadable();
        }
    }

    /**
     * One service that cannot be translated is unknown; it does not take the others down with it.
     */
    private function serviceHealthOrUnknown(MonitoredService $service, ProbeResult $result): ServiceHealth
    {
        try {
            return $this->serviceHealth($service, $result);
        } catch (Throwable $throwable) {
            report($throwable);

            return new ServiceHealth($service, HealthState::Unknown, 'The health of this service could not be read.', new DateTimeImmutable());
        }
    }

    private function serviceHealth(MonitoredService $service, ProbeResult $result): ServiceHealth
    {
        $reason = (string) UrlScrubber::scrub($result->reason);

        return new ServiceHealth(
            service: $service,
            state: HealthState::tryFrom($result->state->value) ?? HealthState::Unknown,
            reason: '' === mb_trim($reason) ? 'The probe gave no reason.' : $reason,
            checkedAt: $result->checkedAt->toDateTimeImmutable(),
            endpoint: $this->endpoint($service, $result->details),
            metrics: match ($service) {
                MonitoredService::Reverb          => $this->reverbMetrics($result->details),
                MonitoredService::Gateway         => $this->gatewayMetrics($result->details),
                MonitoredService::WebhookRegistry => $this->registryMetrics($result->details),
                MonitoredService::Queues          => $this->queueMetrics($result->details),
            },
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    private function endpoint(MonitoredService $service, array $details): ?string
    {
        $value = match ($service) {
            MonitoredService::Reverb  => $details['address'] ?? null,
            MonitoredService::Gateway => $details['url'] ?? null,
            default                   => null,
        };

        return is_string($value) && '' !== mb_trim($value) ? UrlScrubber::scrub($value) : null;
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return list<HealthMetric>
     */
    private function reverbMetrics(array $details): array
    {
        if (! array_key_exists('connections', $details)) {
            return [];
        }

        // `channel_names` is left out on purpose: a channel name can carry a tenant id.
        return [
            new HealthMetric('connections', $this->integer($details['connections'] ?? null), HealthUnit::Count),
            new HealthMetric('channels', $this->integer($details['channels'] ?? null), HealthUnit::Count),
        ];
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return list<HealthMetric>
     */
    private function gatewayMetrics(array $details): array
    {
        $metrics = [];

        foreach ($this->platformMap($details['specs'] ?? null) as $platform => $status) {
            $metrics[] = new HealthMetric('spec_status', is_string($status) ? $status : null, HealthUnit::Text, $platform);
        }

        foreach ($this->platformMap($details['drift'] ?? null) as $platform => $count) {
            $metrics[] = new HealthMetric('drifted_channels', $this->integer($count), HealthUnit::Count, $platform);
        }

        return $metrics;
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return list<HealthMetric>
     */
    private function registryMetrics(array $details): array
    {
        $metrics = [];

        foreach (['missing', 'stale', 'orphaned'] as $name) {
            if (array_key_exists($name, $details)) {
                $metrics[] = new HealthMetric($name, $this->integer($details[$name]), HealthUnit::Count);
            }
        }

        return $metrics;
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return list<HealthMetric>
     */
    private function queueMetrics(array $details): array
    {
        $metrics = [];
        $queues  = $details['queues'] ?? null;

        foreach (is_array($queues) ? $queues : [] as $queue) {
            $name = is_array($queue) ? ($queue['name'] ?? null) : null;

            if (! is_string($name) || '' === mb_trim($name)) {
                continue;
            }

            $metrics[] = new HealthMetric('wait_seconds', $this->number($queue['wait_seconds'] ?? null), HealthUnit::Seconds, $name);
            $metrics[] = new HealthMetric('throughput', $this->number($queue['jobs_per_minute'] ?? null), HealthUnit::PerMinute, $name);
        }

        if (array_key_exists('failed', $details)) {
            $metrics[] = new HealthMetric('failed_jobs', $this->integer($details['failed']), HealthUnit::Count);
        }

        return $metrics;
    }

    /**
     * @return array<string, mixed>
     */
    private function platformMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_filter($value, static fn (mixed $_, int|string $platform): bool => is_string($platform) && '' !== mb_trim($platform), ARRAY_FILTER_USE_BOTH);
    }

    private function integer(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function number(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : null;
    }

    /**
     * Every service the contract knows, as unknown: what the caller gets when the report itself could not be built.
     */
    private function unreadable(): InstallationHealthReport
    {
        $now = new DateTimeImmutable();

        return new InstallationHealthReport(
            array_values(array_map(
                static fn (MonitoredService $service): ServiceHealth => new ServiceHealth(
                    $service,
                    HealthState::Unknown,
                    'The installation health could not be read.',
                    $now,
                ),
                self::SERVICES,
            )),
            $now,
        );
    }
}
