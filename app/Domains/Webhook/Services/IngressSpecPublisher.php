<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use App\Domains\Channels\Enums\ChannelTypeEnum;
use FAPost\Foundation\Channel\Ingress\IngressSpec;
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
 * change: register the adapter, republish, and the gateway can verify it.
 */
final readonly class IngressSpecPublisher
{
    private const string KEY_PREFIX = 'ingress:spec:';

    public function __construct(
        private IngressSpecResolver $resolver,
    ) {
    }

    /**
     * Write a spec for every adapter that publishes one, and drop stale entries
     * for adapters that no longer do.
     *
     * Removal matters as much as writing: an adapter whose verification outgrew
     * the declarative form must stop being verifiable by the gateway, otherwise
     * the gateway would keep applying rules the adapter has abandoned.
     *
     * @return array<string, IngressSpec> Published specs, keyed by platform.
     *
     * @throws JsonException
     */
    public function publishAll(): array
    {
        $published = [];

        foreach (ChannelTypeEnum::cases() as $type) {
            $spec = $this->resolver->specFor($type->value);

            if (null === $spec) {
                $this->forget($type->value);

                continue;
            }

            Redis::set($this->key($type->value), json_encode($spec, JSON_THROW_ON_ERROR));

            $published[$type->value] = $spec;
        }

        return $published;
    }

    public function forget(string $platform): void
    {
        Redis::del($this->key($platform));
    }

    private function key(string $platform): string
    {
        return self::KEY_PREFIX . $platform;
    }
}
