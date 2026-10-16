<?php

declare(strict_types=1);

namespace Tests\Support;

use Fapost\Foundation\Quota\Contracts\PeriodUsageInterface;
use Fapost\Foundation\Quota\DTO\PeriodUsageReport;
use Throwable;

/**
 * {@see PeriodUsageInterface} for tests: answers one report (or throws) and keeps every call.
 */
final class FakePeriodUsage implements PeriodUsageInterface
{
    /**
     * @var list<array{tenantId: string, locale: string}>
     */
    public array $calls = [];

    public function __construct(
        public ?PeriodUsageReport $report = null,
        public ?Throwable $failure = null,
    ) {
    }

    public function reportFor(string $tenantId, string $locale): ?PeriodUsageReport
    {
        $this->calls[] = ['tenantId' => $tenantId, 'locale' => $locale];

        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->report;
    }
}
