<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use Fapost\Foundation\Channel\Ingress\IngressSpec;
use Illuminate\Support\Facades\Redis;
use JsonException;

/**
 * Publishes declarative ingress specs to Redis for consumption by ingress
 * runtimes that live outside this application.
 *
 * Specs are keyed by platform rather than by channel: the rules are a property
 * of the platform, so one entry serves every channel of that platform and the
 * per-channel registry stays as small as it is today.
 *
 * Publishing is derived entirely from the adapters currently registered, which
 * is what keeps a new channel — including one shipped by a plugin — a pure PHP
 * change: register the adapter, republish, and the gateway can verify it. A type that is no longer registered (a
 * removed connector) loses its spec on the next publish: the types written so far are kept in a Redis set, so no key
 * scan is needed and no process keeps the list.
 */
final readonly class IngressSpecPublisher
{
    private const string KEY_PREFIX = 'ingress:spec:';

    /**
     * Types a spec was published for. Not under {@see KEY_PREFIX}: a type could be named like it.
     */
    private const string INDEX_KEY = 'ingress:specs';

    public function __construct(
        private IngressSpecResolver $resolver,
        private ChannelRegistryInterface $channelRegistry,
    ) {
    }

    /**
     * Write a spec for every adapter that publishes one, and drop stale entries
     * for adapters that no longer do.
     *
     * Removal matters as much as writing: a type that no longer has an adapter
     * (a connector removed from the image) must stop being verifiable by the
     * gateway, otherwise the gateway would keep applying rules nobody serves.
     *
     * @return array<string, IngressSpec> Published specs, keyed by platform.
     *
     * @throws JsonException
     */
    public function publishAll(): array
    {
        $published = [];
        $types     = $this->channelRegistry->types();

        foreach ($types as $type) {
            $spec = $this->resolver->specFor($type);

            if (null === $spec) {
                $this->forget($type);

                continue;
            }

            Redis::set($this->key($type), json_encode($spec, JSON_THROW_ON_ERROR));
            Redis::sadd(self::INDEX_KEY, $type);

            $published[$type] = $spec;
        }

        foreach (array_diff($this->indexed(), $types) as $gone) {
            $this->forget($gone);
        }

        return $published;
    }

    public function forget(string $platform): void
    {
        Redis::del($this->key($platform));
        Redis::srem(self::INDEX_KEY, $platform);
    }

    /**
     * @return list<string>
     */
    private function indexed(): array
    {
        $members = Redis::smembers(self::INDEX_KEY);

        return is_array($members) ? array_values(array_map(strval(...), $members)) : [];
    }

    private function key(string $platform): string
    {
        return self::KEY_PREFIX . $platform;
    }
}
