<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Logging\FlowLogStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use RuntimeException;

/**
 * What the flows of the whole tenant are doing, for the admin dashboard: published flows, sessions waiting for
 * something, and node executions per day.
 *
 * `flow_logs` has no tenant column (its entries are the tenant's through their session) and is range-partitioned by
 * month, so the daily read is bounded by `created_at` and scoped through `flow_sessions.tenant_id`.
 */
final readonly class TenantFlowActivity
{
    /**
     * How many days the activity chart covers, today included.
     */
    public const int DAYS = 14;

    public function __construct(
        private TenantContextInterface $tenants,
        private ConnectionInterface $connection,
    ) {
    }

    /**
     * Flow versions published in the tenant (rows of `flow_definitions`), as the Filament stat counted them.
     */
    public function publishedFlows(): int
    {
        return FlowDefinition::query()->where('tenant_id', $this->tenantId())->count();
    }

    /**
     * Sessions paused or waiting for the contact's input.
     */
    public function waitingSessions(): int
    {
        return FlowSession::query()
            ->where('tenant_id', $this->tenantId())
            ->whereIn('status', [FlowSessionStatus::WaitingInput->value, FlowSessionStatus::Paused->value])
            ->count();
    }

    /**
     * Node executions per UTC day for the last {@see self::DAYS} days, oldest first, every day present.
     *
     * `executed` counts `executed` and `terminal` entries (a terminal step is a normal completion), `failed` counts
     * `failed`; `conflict`, which the engine never writes today, counts in neither.
     *
     * `app.timezone` is UTC and `created_at` is stored in UTC on every driver, so the day cut in SQL and the days
     * built here are the same calendar days. PostgreSQL converts explicitly: `TO_CHAR` on a `timestamptz` renders in
     * the session's TimeZone, which an installation may set to anything.
     *
     * @return list<array{date: string, executed: int, failed: int}>
     */
    public function daily(CarbonImmutable $now): array
    {
        $now   = $now->utc();
        $start = $now->subDays(self::DAYS - 1)->startOfDay();

        $day = match ($driver = $this->connection->getDriverName()) {
            'pgsql'  => "TO_CHAR(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD')",
            'sqlite' => "strftime('%Y-%m-%d', created_at)",
            default  => throw new RuntimeException("TenantFlowActivity: unsupported database driver [{$driver}]."),
        };

        $rows = $this->connection->table('flow_logs')
            ->selectRaw("{$day} AS day, status, COUNT(*) AS total")
            ->whereBetween('created_at', [$start, $now])
            ->whereIn('session_id', fn (Builder $sessions): Builder => $sessions
                ->select('id')
                ->from('flow_sessions')
                ->where('tenant_id', $this->tenantId()))
            ->groupByRaw("{$day}, status")
            ->get();

        /** @var array<string, array{date: string, executed: int, failed: int}> $days */
        $days = [];

        for ($i = 0; $i < self::DAYS; $i++) {
            $date        = $start->addDays($i)->toDateString();
            $days[$date] = ['date' => $date, 'executed' => 0, 'failed' => 0];
        }

        foreach ($rows as $row) {
            $date = (string) $row->day;

            if (! isset($days[$date])) {
                continue;
            }

            // Exhaustive on purpose: a new status must not fall silently into either bucket.
            match (FlowLogStatus::from((string) $row->status)) {
                FlowLogStatus::Executed, FlowLogStatus::Terminal => $days[$date]['executed'] += (int) $row->total,
                FlowLogStatus::Failed                            => $days[$date]['failed'] += (int) $row->total,
                FlowLogStatus::Conflict                          => null,
            };
        }

        return array_values($days);
    }

    private function tenantId(): string
    {
        return $this->tenants->get()->getId();
    }
}
