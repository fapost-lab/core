<?php

declare(strict_types=1);

namespace App\Domains\Health\Contracts;

use App\Domains\Health\ValueObjects\ProbeResult;

/**
 * One question about the installation, asked of one source.
 *
 * A probe never throws for an unhealthy or unreachable source: that is its answer, and it gives it as a
 * {@see ProbeResult}. It does no caching of its own; {@see \App\Domains\Health\Services\HealthService} does that, so a
 * command that wants a live answer can ask the probe directly.
 */
interface HealthProbe
{
    /**
     * Stable identifier, used as the key of the report and of the cache entry.
     */
    public function key(): string;

    /**
     * How many seconds an answer stays fresh enough to be served again.
     */
    public function cacheTtl(): int;

    public function check(): ProbeResult;
}
