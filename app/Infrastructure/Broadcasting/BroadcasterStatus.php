<?php

declare(strict_types=1);

namespace App\Infrastructure\Broadcasting;

use Illuminate\Contracts\Config\Repository;

/**
 * Whether Laravel broadcasting goes anywhere. `null` (Core's default) and `log` deliver to nobody, so with them the
 * server raises no broadcast event and the browser polls instead of subscribing.
 *
 * Read per call from the configuration it is built with; not a singleton (see conventions/worker-safety.md).
 *
 * @phpstan-type ClientOptions array{driver: 'reverb'|'pusher', key: string, cluster: string|null, host: string|null, port: int|null, scheme: 'http'|'https'|null}
 */
final readonly class BroadcasterStatus
{
    /** @var list<string> */
    private const array INERT = ['', 'null', 'log'];

    /**
     * Drivers the console's Echo client can speak: both are the Pusher protocol. Any other driver still delivers to the
     * server side, but the browser has no way to listen, so it polls.
     *
     * @var list<string>
     */
    private const array CLIENT_DRIVERS = ['reverb', 'pusher'];

    public function __construct(
        private Repository $config,
    ) {
    }

    public function name(): string
    {
        $name = $this->config->get('broadcasting.default', 'null');

        return is_string($name) ? $name : 'null';
    }

    public function enabled(): bool
    {
        return ! in_array($this->name(), self::INERT, true);
    }

    /**
     * What the browser needs to open the websocket, or null when it cannot listen (nothing delivers, a driver the
     * client does not speak, or no app key). Public values only: the app key is meant for browsers, the secret never
     * leaves the server. A null host, port or scheme means the page's own origin (see config/broadcasting.php).
     *
     * @return ClientOptions|null
     */
    public function client(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $connection = $this->config->get('broadcasting.connections.' . $this->name());
        $driver     = is_array($connection) ? ($connection['driver'] ?? null) : null;
        $key        = is_array($connection) ? ($connection['key'] ?? null) : null;

        if (! is_string($driver) || ! in_array($driver, self::CLIENT_DRIVERS, true) || ! is_string($key) || '' === $key) {
            return null;
        }

        /** @var 'reverb'|'pusher' $driver */
        $cluster = 'pusher' === $driver ? ($connection['options']['cluster'] ?? null) : null;

        return [
            'driver'  => $driver,
            'key'     => $key,
            'cluster' => is_string($cluster) && '' !== $cluster ? $cluster : null,
            'host'    => $this->stringOrNull('broadcasting.client.host'),
            'port'    => $this->portOrNull(),
            'scheme'  => $this->schemeOrNull(),
        ];
    }

    private function stringOrNull(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && '' !== mb_trim($value) ? mb_trim($value) : null;
    }

    private function portOrNull(): ?int
    {
        $value = $this->config->get('broadcasting.client.port');

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @return 'http'|'https'|null
     */
    private function schemeOrNull(): ?string
    {
        $value = $this->stringOrNull('broadcasting.client.scheme');

        return match (null === $value ? null : mb_strtolower($value)) {
            'http'  => 'http',
            'https' => 'https',
            default => null,
        };
    }
}
