<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Enums\UsageState;
use App\Domains\Tenancy\ValueObjects\TenantUsageView;
use App\Domains\Tenancy\ValueObjects\UsageRow;
use DateTimeImmutable;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\Contracts\PeriodUsageInterface;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Fapost\Foundation\Quota\DTO\LimitDefinition;
use Fapost\Foundation\Quota\DTO\PeriodUsage;
use Fapost\Foundation\Quota\DTO\PeriodUsageReport;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Throwable;

/**
 * Where every limit stands for the current tenant: Core's own count for Records and Bytes keys (the same
 * counters the refusals use) and the operator's report for per-period keys.
 *
 * Built for one page view, so not a singleton: it reads the scoped tenant context and asks the operator
 * at most once. It never enforces anything and never breaks the page: a failing operator or counter turns
 * its rows into {@see UsageState::Unavailable} (reported), and an unknown limit is never shown as "no limit".
 */
final readonly class TenantUsageOverview
{
    /**
     * From this share of the limit a row is "almost used up". A display constant, not the operator's
     * notification threshold.
     */
    public const int NEAR_PERCENT = 80;

    public function __construct(
        private TenantContextInterface $context,
        private LimitRegistryInterface $registry,
        private TenantLimitsInterface $limits,
        private PeriodUsageInterface $periodUsage,
        private TenantUsageCounters $counters,
    ) {
    }

    public function overview(string $locale): TenantUsageView
    {
        $tenantId    = mb_strtolower($this->context->get()->getId());
        $definitions = $this->registry->all();
        $report      = null;
        $reportFails = false;

        if ($this->hasPerPeriodKey($definitions)) {
            try {
                $report = $this->periodUsage->reportFor($tenantId, $locale);
            } catch (Throwable $exception) {
                report($exception);
                $reportFails = true;
            }
        }

        $rows = [];

        foreach ($definitions as $definition) {
            $row = LimitKind::PerPeriod === $definition->kind
                ? $this->perPeriodRow($tenantId, $definition, $report, $reportFails)
                : $this->countedRow($tenantId, $definition);

            if (null !== $row) {
                $rows[] = $row;
            }
        }

        return new TenantUsageView(
            $rows,
            $this->action($report),
            array_any($rows, static fn (UsageRow $row): bool => null !== $row->limit || UsageState::Unavailable === $row->state),
        );
    }

    /**
     * @param  list<LimitDefinition>  $definitions
     */
    private function hasPerPeriodKey(array $definitions): bool
    {
        return array_any($definitions, static fn (LimitDefinition $definition): bool => LimitKind::PerPeriod === $definition->kind);
    }

    private function countedRow(string $tenantId, LimitDefinition $definition): ?UsageRow
    {
        $failed = false;
        $limit  = $this->limitFor($tenantId, $definition->key, $failed);
        $used   = null;
        $count  = $this->counters->find($definition->key);

        if (null !== $count) {
            try {
                $used = $count();
            } catch (Throwable $exception) {
                report($exception);
                $failed = true;
            }
        }

        if (! $failed && null === $limit && null === $used) {
            return null;
        }

        return $this->row($definition, $used, $limit, $failed);
    }

    private function perPeriodRow(string $tenantId, LimitDefinition $definition, ?PeriodUsageReport $report, bool $reportFails): ?UsageRow
    {
        $entry = $report?->find($definition->key);

        if ($entry instanceof PeriodUsage) {
            return $this->row($definition, $entry->used, $entry->limit, false, $entry->periodEndsAt);
        }

        $failed = $reportFails;
        $limit  = $this->limitFor($tenantId, $definition->key, $failed);

        if (! $failed && null === $limit) {
            return null;
        }

        return $this->row($definition, null, $limit, $failed);
    }

    /**
     * The tenant's limit, or null when there is none or it could not be read ($failed is set then).
     */
    private function limitFor(string $tenantId, string $key, bool &$failed): ?int
    {
        try {
            return $this->limits->limitFor($tenantId, $key);
        } catch (Throwable $exception) {
            report($exception);
            $failed = true;

            return null;
        }
    }

    private function row(LimitDefinition $definition, ?int $used, ?int $limit, bool $failed, ?DateTimeImmutable $periodEndsAt = null): UsageRow
    {
        return new UsageRow(
            $definition->key,
            $definition->label,
            $definition->kind,
            $used,
            $limit,
            $this->percent($used, $limit),
            $this->state($used, $limit, $failed),
            $periodEndsAt,
        );
    }

    private function percent(?int $used, ?int $limit): ?int
    {
        if (null === $used || null === $limit) {
            return null;
        }

        if ($limit <= 0) {
            return 100;
        }

        return min(100, intdiv($used * 100, $limit));
    }

    private function state(?int $used, ?int $limit, bool $failed): UsageState
    {
        return match (true) {
            $failed                                    => UsageState::Unavailable,
            null === $limit                            => UsageState::Unlimited,
            null === $used                             => UsageState::NotReported,
            $used >= $limit                            => UsageState::Reached,
            $used * 100 >= self::NEAR_PERCENT * $limit => UsageState::Near,
            default                                    => UsageState::Ok,
        };
    }

    /**
     * @return array{label: string, url: string}|null
     */
    private function action(?PeriodUsageReport $report): ?array
    {
        if (null === $report || null === $report->actionLabel || null === $report->actionUrl) {
            return null;
        }

        return ['label' => $report->actionLabel, 'url' => $report->actionUrl];
    }
}
