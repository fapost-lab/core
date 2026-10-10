<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Logging\FlowLogStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use RuntimeException;

/**
 * What the flows of the tenant are doing, for the admin dashboard: published flows, sessions waiting for something,
 * and node executions per day. Each figure is the whole tenant's, or only that of the given assistants (the ones a
 * non-administrator may list).
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
     *
     * With `$assistants` only the versions of flows whose draft belongs to one of those assistants: a definition has no
     * assistant of its own, its draft (`flow_drafts.flow_id`) has.
     *
     * @param  EloquentBuilder<Assistant>|null  $assistants  the assistants to count for; `null` is the whole tenant
     */
    public function publishedFlows(?EloquentBuilder $assistants = null): int
    {
        $query = FlowDefinition::query()->where('tenant_id', $this->tenantId());

        if (null !== $assistants) {
            $query->whereIn('flow_id', fn (Builder $drafts): Builder => $drafts
                ->select('flow_id')
                ->from('flow_drafts')
                ->where('tenant_id', $this->tenantId())
                ->whereIn('assistant_id', $this->assistantIds($assistants)));
        }

        return $query->count();
    }

    /**
     * Sessions paused or waiting for the contact's input.
     *
     * @param  EloquentBuilder<Assistant>|null  $assistants  the assistants to count for; `null` is the whole tenant
     */
    public function waitingSessions(?EloquentBuilder $assistants = null): int
    {
        $query = FlowSession::query()
            ->where('tenant_id', $this->tenantId())
            ->whereIn('status', [FlowSessionStatus::WaitingInput->value, FlowSessionStatus::Paused->value]);

        if (null !== $assistants) {
            $query->whereIn('assistant_id', $this->assistantIds($assistants));
        }

        return $query->count();
    }

    /**
     * Node executions per UTC day for the last {@see self::DAYS} days, oldest first, every day present.
     *
     * `executed` counts `executed` and `terminal` entries (a terminal step is a normal completion), `failed` counts
     * `failed`; `conflict`, which the engine never writes today, counts in neither.
     *
     * The day cut is UTC on both drivers: SQLite stores `created_at` in the app timezone (UTC), and PostgreSQL converts
     * explicitly (`AT TIME ZONE 'UTC'`), since `TO_CHAR` on a `timestamptz` renders in the session's TimeZone. The
     * window's bounds are not converted: they are bound as timestamps without a zone, which PostgreSQL reads in the
     * session's TimeZone, so the first and last day line up with UTC days only while that session TimeZone is UTC.
     * Under another TimeZone the window shifts by the offset; the days themselves stay UTC days.
     *
     * @param  EloquentBuilder<Assistant>|null  $assistants  the assistants to count for; `null` is the whole tenant
     *
     * @return list<array{date: string, executed: int, failed: int}>
     */
    public function daily(CarbonImmutable $now, ?EloquentBuilder $assistants = null): array
    {
        $now   = $now->utc();
        $start = $now->subDays(self::DAYS - 1)->startOfDay();

        $day = match ($driver = $this->connection->getDriverName()) {
            'pgsql'  => "TO_CHAR(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD')",
            'sqlite' => "strftime('%Y-%m-%d', created_at)",
            default  => throw new RuntimeException("TenantFlowActivity: unsupported database driver [{$driver}]."),
        };

        $ids  = null === $assistants ? null : $this->assistantIds($assistants);
        $rows = $this->connection->table('flow_logs')
            ->selectRaw("{$day} AS day, status, COUNT(*) AS total")
            ->whereBetween('created_at', [$start, $now])
            ->whereIn('session_id', fn (Builder $sessions): Builder => $sessions
                ->select('id')
                ->from('flow_sessions')
                ->where('tenant_id', $this->tenantId())
                ->when(null !== $ids, static fn (Builder $scoped): Builder => $scoped->whereIn('assistant_id', $ids)))
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

    /**
     * The ids of the given assistants, as a subquery.
     *
     * @param  EloquentBuilder<Assistant>  $assistants
     *
     * @return EloquentBuilder<Assistant>
     */
    private function assistantIds(EloquentBuilder $assistants): EloquentBuilder
    {
        return (clone $assistants)->select('assistants.id');
    }

    private function tenantId(): string
    {
        return $this->tenants->get()->getId();
    }
}
