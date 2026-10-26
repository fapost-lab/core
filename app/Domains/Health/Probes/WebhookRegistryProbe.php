<?php

declare(strict_types=1);

namespace App\Domains\Health\Probes;

use App\Domains\Health\Contracts\HealthProbe;
use App\Domains\Health\ValueObjects\ProbeResult;
use App\Domains\Tenancy\Contracts\WebhookRegistryHealthCheckerInterface;
use Throwable;

/**
 * Compares the Redis webhook routing cache with the landlord registry, the check `ops:webhook-registry-health` makes.
 *
 * Drift is a definite finding rather than an unanswered question, so it is "down": the entries named in the details
 * are webhooks that take the slow path or are routed on stale data. A registry that cannot be read says nothing about
 * itself and is "unknown".
 */
final readonly class WebhookRegistryProbe implements HealthProbe
{
    public const string KEY = 'webhook_registry';

    public function __construct(private WebhookRegistryHealthCheckerInterface $checker)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * Longer than the network probes: the comparison reads every registry row and scans the Redis keys.
     */
    public function cacheTtl(): int
    {
        return 30;
    }

    public function check(): ProbeResult
    {
        try {
            $report = $this->checker->check();
        } catch (Throwable $throwable) {
            return ProbeResult::unknown(
                self::KEY,
                'The registry could not be compared with Redis: ' . $throwable::class . '.',
            );
        }

        $details = [
            'missing'  => count($report->missing),
            'stale'    => count($report->stale),
            'orphaned' => count($report->orphaned),
        ];

        if ($report->isHealthy()) {
            return ProbeResult::up(self::KEY, 'The webhook routing cache matches the registry.', $details);
        }

        return ProbeResult::down(
            self::KEY,
            sprintf(
                'The webhook routing cache differs from the registry: %d missing, %d stale, %d orphaned. Run php artisan ops:webhook-registry-health --repair.',
                $details['missing'],
                $details['stale'],
                $details['orphaned'],
            ),
            $details,
        );
    }
}
