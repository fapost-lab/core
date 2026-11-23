<?php

declare(strict_types=1);

namespace App\Domains\Flow\Api;

use InvalidArgumentException;

/**
 * The config of an API trigger, as `flow_triggers.config` and the draft carry it: only `response_mode` — `async` (202
 * once the run is queued, the default) or `sync` (the request waits for the flow's answer, as a webhook's does).
 *
 * The caller is authenticated by a tenant API token, never by anything in the config. Any other key is refused, the
 * `route_key` and `allowed_sources` of the old shape included: such a trigger never worked, and it does not publish
 * until it is set again in the builder; a live row that still carries them is answered 404.
 */
final readonly class ApiTriggerConfig
{
    public const string RESPONSE_ASYNC = 'async';

    public const string RESPONSE_SYNC = 'sync';

    /** @var list<string> */
    private const array KEYS = ['response_mode'];

    private function __construct(
        public string $responseMode,
    ) {
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidArgumentException when the config is not a valid API trigger config
     */
    public static function fromConfig(array $config): self
    {
        $unknown = array_diff(array_keys($config), self::KEYS);

        if ([] !== $unknown) {
            throw new InvalidArgumentException('Invalid api trigger config: unknown key(s) ' . implode(', ', $unknown) . '.');
        }

        $responseMode = $config['response_mode'] ?? self::RESPONSE_ASYNC;

        if (self::RESPONSE_ASYNC !== $responseMode && self::RESPONSE_SYNC !== $responseMode) {
            throw new InvalidArgumentException('Invalid api trigger config: response_mode must be async or sync.');
        }

        return new self($responseMode);
    }

    /** Whether a call waits for the flow's answer instead of getting 202 at once. */
    public function isSync(): bool
    {
        return self::RESPONSE_SYNC === $this->responseMode;
    }
}
