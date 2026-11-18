<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Flow\Call\CallExecutionLedgerInterface;
use Fapost\Foundation\Flow\Call\CallResult;

/**
 * {@see CallExecutionLedgerInterface} for tests that need no Redis: keeps the records and counters in arrays.
 */
final class InMemoryCallExecutionLedger implements CallExecutionLedgerInterface
{
    /**
     * @var array<string, CallResult>
     */
    public array $completed = [];

    /**
     * @var array<string, int>
     */
    public array $attempts = [];

    /**
     * How many times {@see self::completed()} was asked.
     */
    public int $lookups = 0;

    public function completed(string $tenantId, string $key): ?CallResult
    {
        $this->lookups++;

        return $this->completed["{$tenantId}|{$key}"] ?? null;
    }

    public function startAttempt(string $tenantId, string $key): int
    {
        return $this->attempts["{$tenantId}|{$key}"] = ($this->attempts["{$tenantId}|{$key}"] ?? 0) + 1;
    }

    public function complete(string $tenantId, string $key, CallResult $result): void
    {
        $this->completed["{$tenantId}|{$key}"] = $result;
    }
}
