<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use App\Domains\Webhook\Enums\IngressDriver;
use InvalidArgumentException;

/**
 * Builds the public URL a provider is told to deliver webhooks to.
 *
 * Two ingress runtimes can be live at once — the in-app Laravel route and an
 * external gateway — and this generator decides which one a channel is pointed
 * at when it is registered. The choice is deliberately made at registration
 * time only: already-registered channels keep the URL the provider stored, and
 * both hosts serve the same path, so nothing needs to migrate in lockstep.
 *
 * The path is identical across runtimes (`/webhook/{channel}/{hash}`), which
 * keeps switching to a base-URL swap and lets the gateway proxy unknown
 * platforms straight through to the Laravel route.
 */
final readonly class WebhookUrlGenerator
{
    /**
     * @param  string|null           $baseUrl           Laravel ingress base URL (config `webhook.base_url`).
     * @param  string|null           $gatewayUrl        External gateway base URL (config `webhook.ingress.gateway_url`).
     * @param  list<string>          $gatewayPlatforms  Platforms the gateway can verify.
     */
    public function __construct(
        private ?string $baseUrl = null,
        private ?string $gatewayUrl = null,
        private IngressDriver $driver = IngressDriver::Laravel,
        private array $gatewayPlatforms = [],
    ) {
    }

    public function forChannel(string $channel, string $hash): string
    {
        return $this->baseFor($channel) . '/webhook/' . rawurlencode($channel) . '/' . rawurlencode($hash);
    }

    /**
     * Base URL this channel would be registered against right now.
     *
     * Exposed separately so callers can record which ingress a channel actually
     * went to, rather than re-deriving it from config later — after a driver
     * switch that derivation would no longer describe reality.
     */
    public function baseFor(string $channel): string
    {
        $base = $this->usesGateway($channel)
            ? (string) $this->gatewayUrl
            : (string) $this->baseUrl;

        if ('' === $base) {
            throw new InvalidArgumentException('Webhook base URL is not configured.');
        }

        return mb_rtrim($base, '/');
    }

    /**
     * Whether this channel is routed to the gateway.
     *
     * Every condition must hold: the driver is switched on, a gateway URL exists,
     * and the gateway knows how to verify this platform. Failing any of them
     * falls back to the Laravel route, which always works.
     */
    public function usesGateway(string $channel): bool
    {
        return IngressDriver::Gateway === $this->driver
            && '' !== (string) $this->gatewayUrl
            && in_array($channel, $this->gatewayPlatforms, true);
    }
}
