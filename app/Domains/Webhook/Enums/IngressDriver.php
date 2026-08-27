<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Enums;

/**
 * Which ingress runtime newly registered channels should point at.
 *
 * Switching the driver only affects URLs handed to providers from that moment on:
 * channels already registered keep the URL they were registered with, and the
 * Laravel route stays live regardless so both ingress paths remain valid.
 */
enum IngressDriver: string
{
    /** Register webhooks against the in-app Laravel route. */
    case Laravel = 'laravel';

    /** Register webhooks against the external ingress gateway. */
    case Gateway = 'gateway';

    /**
     * Resolve a configured value, falling back to the safe default.
     *
     * A typo in the environment must not silently route live traffic at a host
     * that may not exist, so anything unrecognized degrades to the Laravel route.
     */
    public static function fromConfig(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Laravel;
    }
}
