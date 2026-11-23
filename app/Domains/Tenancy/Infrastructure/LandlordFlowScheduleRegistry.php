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

    /**
     * Only rows written before {@code $readAt} are changed: the list was read then, so a row written since (a publish's
     * {@see put()} that committed while the tenant was being read) is newer than it. Times are compared at the second,
     * the precision `updated_at` keeps, so a row of the very second of the read is kept too; a row kept that way is at
     * worst one useless visit, and the next rebuild puts it right.
     */
    public function replaceForTenant(string $tenantId, array $nextRuns, DateTimeInterface $readAt): void
    {
        $before = $this->format($readAt);

        DB::connection('landlord')->transaction(function () use ($tenantId, $nextRuns, $before): void {
            $rows = [];

            foreach ($nextRuns as $triggerId => $nextRunAt) {
                $row                      = $this->row($tenantId, (string) $triggerId, $nextRunAt);
                $rows[$row['trigger_id']] = $row;
            }

            $stored = $this->table()
                ->where('tenant_id', $tenantId)
                ->pluck('trigger_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            $gone = array_values(array_diff($stored, array_keys($rows)));

            foreach (array_chunk($gone, 500) as $chunk) {
                $this->table()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('trigger_id', $chunk)
                    ->where('updated_at', '<', $before)
                    ->delete();
            }

            foreach (array_chunk($rows, 500, true) as $chunk) {
                $existing = $this->table()
                    ->whereIn('trigger_id', array_keys($chunk))
                    ->get(['trigger_id', 'tenant_id', 'next_run_at'])
                    ->keyBy(static fn (object $row): string => (string) $row->trigger_id);

                $new = [];

                foreach ($chunk as $triggerId => $row) {
                    $current = $existing->get($triggerId);

                    if (null === $current) {
                        $new[] = $row;

                        continue;
                    }

                    if ($tenantId === (string) $current->tenant_id && $this->sameMoment($current->next_run_at, $row['next_run_at'])) {
                        continue;
                    }

                    // A changed slot, or a trigger id another tenant's row still claims (a stale copy) moving here.
                    $this->table()
                        ->where('trigger_id', $triggerId)
                        ->where('updated_at', '<', $before)
                        ->update(['tenant_id' => $tenantId, 'next_run_at' => $row['next_run_at'], 'updated_at' => $row['updated_at']]);
                }

                // A row another write added since the tenant was read is newer: it stays.
                $this->table()->insertOrIgnore($new);
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

    /**
     * Whether a stored `next_run_at` is the moment {@code $formatted}; the driver may return it with or without
     * fractions.
     */
    private function sameMoment(mixed $stored, string $formatted): bool
    {
        return null !== $stored && CarbonImmutable::parse((string) $stored, 'UTC')->format('Y-m-d H:i:s') === $formatted;
    }

    private function format(DateTimeInterface $moment): string
    {
        return CarbonImmutable::createFromTimestampUTC($moment->getTimestamp())->format('Y-m-d H:i:s');
    }
}
