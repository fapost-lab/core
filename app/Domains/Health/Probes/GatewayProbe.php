<?php

declare(strict_types=1);

namespace App\Domains\Health\Probes;

use App\Domains\Channels\Services\IngressMigrator;
use App\Domains\Health\Contracts\HealthProbe;
use App\Domains\Health\ValueObjects\ProbeResult;
use App\Domains\Webhook\Enums\IngressDriver;
use App\Domains\Webhook\Services\IngressSpecPublisher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Asks the Go gateway's `/healthz` whether it is serving, and reports next to the answer what the application knows
 * about the wiring: whether the specs published to Redis match the adapters and how many channels still deliver to
 * the URL they were registered with.
 *
 * Only the health endpoint decides the state. The wiring figures are details: a drifted channel keeps working on the
 * slow path, so it is a thing to read, not a reason to call the gateway down. A `laravel` driver means the gateway is
 * not in use, which is "disabled", not a fault.
 */
final readonly class GatewayProbe implements HealthProbe
{
    public const string KEY = 'gateway';

    private const int TIMEOUT_SECONDS = 3;

    private const int CONNECT_TIMEOUT_SECONDS = 2;

    public function __construct(
        private Repository $config,
        private HttpFactory $http,
        private IngressSpecPublisher $specs,
        private IngressMigrator $migrator,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function cacheTtl(): int
    {
        return 5;
    }

    public function check(): ProbeResult
    {
        $driver = IngressDriver::fromConfig($this->config->get('webhook.ingress.driver'));

        if (IngressDriver::Gateway !== $driver) {
            return ProbeResult::disabled(
                self::KEY,
                'The ingress driver is "laravel": webhooks are received by the application itself.',
                ['driver' => $driver->value],
            );
        }

        $url = (string) $this->config->get('webhook.ingress.gateway_url');

        if ('' === $url) {
            return ProbeResult::unknown(
                self::KEY,
                'The driver is "gateway" but WEBHOOK_GATEWAY_URL is empty, so there is no address to ask.',
                ['driver' => $driver->value],
            );
        }

        $health = $this->probeEndpoint($url);

        return $health->withDetails([
            'driver' => $driver->value,
            'specs'  => $this->specStatuses(),
            'drift'  => $this->drift(),
        ]);
    }

    /**
     * Ask one gateway's `/healthz`, without caching and without reading anything of the application's.
     *
     * Public so that a command can ask a gateway the configuration does not name (`gateway:doctor --url`). The details
     * carry the address that was asked, the HTTP status when there was one and the failure when there was none.
     */
    public function probeEndpoint(string $baseUrl): ProbeResult
    {
        $url = mb_rtrim($baseUrl, '/') . '/healthz';

        try {
            $response = $this->http
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->get($url);
        } catch (Throwable $exception) {
            return ProbeResult::unknown(
                self::KEY,
                "Could not reach {$url}.",
                ['url' => $url, 'status' => null, 'error' => $exception->getMessage()],
            );
        }

        if (! $response->successful()) {
            return ProbeResult::down(
                self::KEY,
                "The gateway answered {$response->status()} at {$url}.",
                ['url' => $url, 'status' => $response->status(), 'error' => null],
            );
        }

        return ProbeResult::up(self::KEY, "The gateway answered at {$url}.", ['url' => $url, 'status' => $response->status(), 'error' => null]);
    }

    /**
     * @return array<string, string>|null Platform to `matches`, `missing` or `differs`; null when Redis could not be read.
     */
    private function specStatuses(): ?array
    {
        try {
            return array_map(static fn (array $spec): string => $spec['status']->value, $this->specs->inspect());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, int>|null Channels per platform still on another ingress than the configured one; null when unreadable.
     */
    private function drift(): ?array
    {
        try {
            return $this->migrator->drift();
        } catch (Throwable) {
            return null;
        }
    }
}
