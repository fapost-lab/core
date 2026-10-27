<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Channels\Services\IngressMigrator;
use App\Domains\Health\Enums\HealthState;
use App\Domains\Health\Probes\GatewayProbe;
use App\Domains\Health\Probes\ReverbProbe;
use App\Domains\Health\ValueObjects\HealthReport;
use App\Domains\Health\ValueObjects\ProbeResult;
use Throwable;

/**
 * Narrows the installation's {@see HealthReport} to what one tenant may read, in `host` mode: Reverb and the gateway,
 * and of each only the part that is the tenant's own.
 *
 * The health service answers for the whole installation and does not filter by tenant; the consumer does, and this is
 * it. The Reverb answer keeps the number of the tenant's open channels (channel names carry the tenant id, so the
 * prefix tells them apart); the gateway answer keeps the number of the tenant's channels still on the old ingress
 * address. Everything that describes the installation is dropped: connections, addresses, published specifications, the
 * reasons (they carry addresses and environment variable names) and every other probe, the queues included.
 */
final readonly class TenantHealthSlice
{
    /** Pusher channel prefixes that may stand before the application's own channel name. */
    private const array CHANNEL_PREFIXES = ['private-encrypted-', 'private-', 'presence-'];

    public function __construct(private IngressMigrator $migrator)
    {
    }

    public function of(HealthReport $report, string $tenantId): HealthReport
    {
        $results = [];

        $reverb = $report->get(ReverbProbe::KEY);

        if (null !== $reverb) {
            $results[ReverbProbe::KEY] = $this->narrowed($reverb, $this->reverbDetails($reverb, $tenantId));
        }

        $gateway = $report->get(GatewayProbe::KEY);

        if (null !== $gateway) {
            $results[GatewayProbe::KEY] = $this->narrowed($gateway, $this->gatewayDetails($gateway, $tenantId));
        }

        return new HealthReport($results);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function narrowed(ProbeResult $result, array $details): ProbeResult
    {
        return ProbeResult::fromArray([...$result->toArray(), 'reason' => '', 'details' => $details]);
    }

    /**
     * @return array<string, mixed>
     */
    private function reverbDetails(ProbeResult $result, string $tenantId): array
    {
        $names = $result->details['channel_names'] ?? null;

        if (! is_array($names)) {
            return [];
        }

        $own = array_filter(
            $names,
            fn (mixed $name): bool => is_string($name) && $this->belongsTo($name, $tenantId),
        );

        return ['channels' => count($own)];
    }

    /**
     * @return array<string, mixed>
     */
    private function gatewayDetails(ProbeResult $result, string $tenantId): array
    {
        if (HealthState::Disabled === $result->state) {
            return [];
        }

        try {
            return ['drift' => $this->migrator->drift($tenantId)];
        } catch (Throwable) {
            return ['drift' => null];
        }
    }

    private function belongsTo(string $channel, string $tenantId): bool
    {
        // Only authorized channels count: every live channel of Core is private, and a public name anyone may
        // subscribe to would let a stranger who knows the tenant id inflate the figure.
        foreach (self::CHANNEL_PREFIXES as $prefix) {
            if (str_starts_with($channel, $prefix)) {
                return str_starts_with(mb_substr($channel, mb_strlen($prefix)), "tenant.{$tenantId}.");
            }
        }

        return false;
    }
}
