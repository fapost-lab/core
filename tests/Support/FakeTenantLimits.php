<?php

declare(strict_types=1);

namespace Tests\Support;

use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;

/**
 * {@see TenantLimitsInterface} answering from an array, for tests. A key without an entry is unlimited.
 */
final class FakeTenantLimits implements TenantLimitsInterface
{
    /**
     * Answers "no limit" this many more times before the configured limits apply; it simulates a
     * limit that is reached between an earlier check and the one in the service.
     */
    public int $unlimitedAnswers = 0;

    /**
     * @param  array<string, int|null>  $limits
     */
    public function __construct(public array $limits = [])
    {
    }

    public function limitFor(string $tenantId, string $key): ?int
    {
        if ($this->unlimitedAnswers > 0) {
            $this->unlimitedAnswers--;

            return null;
        }

        return $this->limits[$key] ?? null;
    }
}
