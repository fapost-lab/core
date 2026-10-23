<?php

declare(strict_types=1);

namespace App\Domains\Health\Probes;

use App\Domains\Health\Contracts\HealthProbe;
use App\Domains\Health\Exceptions\ReverbApiException;
use App\Domains\Health\Services\ReverbApiClient;
use App\Domains\Health\ValueObjects\ProbeResult;
use Illuminate\Contracts\Config\Repository;

/**
 * Asks Reverb, through its own HTTP API, whether it is serving and how many connections and channels it holds.
 *
 * Only a Reverb that answered and said it is unwell is "down". One that cannot be reached from here is "unknown":
 * the application may simply be pointed at the wrong host, and a red card next to a working websocket is the
 * false alarm that makes a health page untrustworthy. Not broadcasting through Reverb at all is "disabled".
 */
final readonly class ReverbProbe implements HealthProbe
{
    public const string KEY = 'reverb';

    public function __construct(
        private Repository $config,
        private ReverbApiClient $client,
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
        $driver = (string) $this->config->get('broadcasting.default');

        if ('reverb' !== $driver) {
            return ProbeResult::disabled(
                self::KEY,
                in_array($driver, ['', 'null', 'log'], true)
                    ? 'Live updates are switched off (BROADCAST_CONNECTION is not set to reverb); the console polls instead.'
                    : "Broadcasting uses the \"{$driver}\" driver, not Reverb.",
                ['driver' => $driver],
            );
        }

        /** @var array<string, mixed> $connection */
        $connection = (array) $this->config->get('broadcasting.connections.reverb', []);
        $options    = (array) ($connection['options'] ?? []);

        $appId  = (string) ($connection['app_id'] ?? '');
        $key    = (string) ($connection['key'] ?? '');
        $secret = (string) ($connection['secret'] ?? '');
        $host   = (string) ($options['host'] ?? '');
        $port   = (int) ($options['port'] ?? 0);
        $scheme = (string) ($options['scheme'] ?? 'https');

        $missing = array_keys(array_filter([
            'REVERB_APP_ID'     => '' === $appId,
            'REVERB_APP_KEY'    => '' === $key,
            'REVERB_APP_SECRET' => '' === $secret,
            'REVERB_HOST'       => '' === $host,
            'REVERB_PORT'       => 0 === $port,
        ]));

        if ([] !== $missing) {
            return ProbeResult::unknown(
                self::KEY,
                'Broadcasting is set to Reverb but ' . implode(', ', $missing) . ' is not set, so its API cannot be asked.',
            );
        }

        $address = "{$host}:{$port}";

        try {
            $connections = $this->client->connections($scheme, $host, $port, $appId, $key, $secret);
            $channels    = $this->client->channels($scheme, $host, $port, $appId, $key, $secret);
        } catch (ReverbApiException $exception) {
            return $this->failure($exception, $address);
        }

        return ProbeResult::up(self::KEY, "Reverb answered at {$address}.", [
            'address'       => $address,
            'connections'   => $connections,
            'channels'      => count($channels),
            'channel_names' => $channels,
        ]);
    }

    private function failure(ReverbApiException $exception, string $address): ProbeResult
    {
        $details = ['address' => $address, 'status' => $exception->status];

        return match (true) {
            null === $exception->status => ProbeResult::unknown(
                self::KEY,
                "Reverb's API at {$address} could not be reached: {$exception->getMessage()}.",
                $details,
            ),
            $exception->status >= 500 => ProbeResult::down(
                self::KEY,
                "Reverb at {$address} {$exception->getMessage()}.",
                $details,
            ),
            in_array($exception->status, [401, 403], true) => ProbeResult::unknown(
                self::KEY,
                "Reverb at {$address} rejected the request signature; REVERB_APP_ID, REVERB_APP_KEY or REVERB_APP_SECRET differ from the server's.",
                $details,
            ),
            default => ProbeResult::unknown(
                self::KEY,
                "Reverb's API at {$address} {$exception->getMessage()}.",
                $details,
            ),
        };
    }
}
