<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Infrastructure;

use App\Domains\Tenancy\Contracts\FlowScheduleRegistryInterface;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The schedule registry in the landlord table `flow_schedule_registry`. This is the only class that opens it.
 *
 * Times are stored as UTC timestamps at minute precision, the form a slot has.
 */
final class LandlordFlowScheduleRegistry implements FlowScheduleRegistryInterface
{
    private const string TABLE = 'flow_schedule_registry';

    public function put(string $tenantId, string $triggerId, DateTimeInterface $nextRunAt): void
    {
        $this->table()->upsert(
            [$this->row($tenantId, $triggerId, $nextRunAt)],
            ['trigger_id'],
            ['tenant_id', 'next_run_at', 'updated_at'],
        );
    }

    public function forget(string $triggerId): void
    {
        $this->table()->where('trigger_id', mb_strtolower($triggerId))->delete();
    }

    public function forgetTenant(string $tenantId): int
    {
        return $this->table()->where('tenant_id', $tenantId)->delete();
    }

    public function replaceForTenant(string $tenantId, array $nextRuns): void
    {
        DB::connection('landlord')->transaction(function () use ($tenantId, $nextRuns): void {
            $this->table()->where('tenant_id', $tenantId)->delete();

            $rows = [];

            foreach ($nextRuns as $triggerId => $nextRunAt) {
                $rows[] = $this->row($tenantId, (string) $triggerId, $nextRunAt);
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                // An upsert, not an insert: a trigger id another tenant's rows still claim (a stale copy) moves here.
                $this->table()->upsert($chunk, ['trigger_id'], ['tenant_id', 'next_run_at', 'updated_at']);
            }
        });
    }

    public function dueTenantIds(DateTimeInterface $now, int $limit): array
    {
        return $this->table()
            ->where('next_run_at', '<=', $this->format($now))
            ->groupBy('tenant_id')
            ->orderByRaw('MIN(next_run_at)')
            ->limit($limit)
            ->pluck('tenant_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    private function table(): Builder
    {
        return DB::connection('landlord')->table(self::TABLE);
    }

    /**
     * @return array{trigger_id: string, tenant_id: string, next_run_at: string, updated_at: string}
     */
    private function row(string $tenantId, string $triggerId, DateTimeInterface $nextRunAt): array
    {
        return [
            'trigger_id'  => mb_strtolower($triggerId),
            'tenant_id'   => $tenantId,
            'next_run_at' => $this->format($nextRunAt),
            'updated_at'  => $this->format(CarbonImmutable::now()),
        ];
    }

    private function format(DateTimeInterface $moment): string
    {
        return CarbonImmutable::createFromTimestampUTC($moment->getTimestamp())->format('Y-m-d H:i:s');
    }
}
