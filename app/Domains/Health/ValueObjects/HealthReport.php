<?php

declare(strict_types=1);

namespace App\Domains\Health\ValueObjects;

/**
 * Every probe's latest answer, keyed by probe.
 */
final readonly class HealthReport
{
    /**
     * @param array<string, ProbeResult> $results
     */
    public function __construct(public array $results)
    {
    }

    public function get(string $probe): ?ProbeResult
    {
        return $this->results[$probe] ?? null;
    }

    /**
     * @return array<string, array{probe: string, state: string, reason: string, details: array<string, mixed>, checked_at: string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (ProbeResult $result): array => $result->toArray(), $this->results);
    }
}
