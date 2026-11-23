<?php

declare(strict_types=1);

namespace App\Domains\Flow\Admission;

use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use Illuminate\Cache\RateLimiter;

/**
 * Counts one trigger's new runs in a fixed one-minute window and says when they go over the platform's limit.
 *
 * The window is the rate limiter's (Redis in production), by tenant and trigger, so one trigger's burst never counts
 * against another's. Every run asking to start is counted, a refused one included: a loop under a used-up plan limit
 * still trips the breaker.
 */
final readonly class TriggerRateBreaker
{
    public const string SETTING = 'flow.automations.runs_per_trigger_per_minute';

    public const int WINDOW_SECONDS = 60;

    public function __construct(
        private RateLimiter $limiter,
        private PlatformSettingsReaderInterface $settings,
    ) {
    }

    /**
     * Count one run of the trigger.
     *
     * @return bool true when the run is over the limit and the breaker trips
     */
    public function hit(string $tenantId, string $triggerId): bool
    {
        $attempts = $this->limiter->hit(self::key($tenantId, $triggerId), self::WINDOW_SECONDS);

        return $attempts > max(1, $this->settings->integer(self::SETTING));
    }

    /**
     * Start the trigger's window over, as a person resuming it expects.
     */
    public function clear(string $tenantId, string $triggerId): void
    {
        $this->limiter->clear(self::key($tenantId, $triggerId));
    }

    private static function key(string $tenantId, string $triggerId): string
    {
        return 'flow-breaker:' . mb_strtolower($tenantId) . ':' . mb_strtolower($triggerId);
    }
}
