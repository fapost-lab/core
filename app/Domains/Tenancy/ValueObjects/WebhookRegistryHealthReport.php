<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\ValueObjects;

/**
 * Diff between the landlord webhook_registry table (source of truth) and the
 * Redis routing cache, grouped by failure mode. Produced by
 * {@see \App\Domains\Tenancy\Services\WebhookRegistryHealthChecker}.
 */
final readonly class WebhookRegistryHealthReport
{
    /**
     * @param  list<string>  $missing   Hashes present in landlord DB but absent in Redis (webhooks degrade to DB fallback).
     * @param  list<string>  $stale     Hashes whose Redis payload differs from the DB row (routing uses outdated data).
     * @param  list<string>  $orphaned  Hashes present in Redis without a DB row (stale routing entries, must be removed).
     */
    public function __construct(
        public array $missing,
        public array $stale,
        public array $orphaned,
    ) {
    }

    public function isHealthy(): bool
    {
        return [] === $this->missing && [] === $this->stale && [] === $this->orphaned;
    }
}
