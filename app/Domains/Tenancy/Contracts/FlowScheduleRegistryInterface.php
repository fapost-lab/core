<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

use DateTimeInterface;

/**
 * The landlord index of schedule triggers that answers one question cheaply: which tenants have a schedule slot due
 * now, so the minute's pass enters only their schemas.
 *
 * It is derived data. The truth is each tenant's `flow_triggers.next_run_at`, and a slot is claimed there; every row
 * here is a copy written after the tenant's transaction committed, and can be rebuilt from the tenants at any time. A
 * row that is early or left over costs one useless visit to a schema; a row that is missing or late is put right by
 * the hourly rebuild (adr-20261123-flow-schedule-trigger).
 */
interface FlowScheduleRegistryInterface
{
    /**
     * Record when a trigger is next due, replacing what was recorded for it.
     */
    public function put(string $tenantId, string $triggerId, DateTimeInterface $nextRunAt): void;

    /**
     * Forget a trigger; nothing happens when it is not recorded.
     */
    public function forget(string $triggerId): void;

    /**
     * Forget every trigger of a tenant. Returns how many rows were removed.
     */
    public function forgetTenant(string $tenantId): int;

    /**
     * Replace what was recorded for a tenant with {@code $nextRuns}, read from the tenant at {@code $readAt}, in one
     * landlord transaction. A row written at or after {@code $readAt} ({@see put()} after a publish that committed
     * while the tenant was being read) is newer than the list and is kept as it is.
     *
     * @param  array<string, DateTimeInterface>  $nextRuns  trigger id => when it is next due
     */
    public function replaceForTenant(string $tenantId, array $nextRuns, DateTimeInterface $readAt): void;

    /**
     * The tenants with at least one trigger due at {@code $now}, the most overdue first.
     *
     * @return list<string>
     */
    public function dueTenantIds(DateTimeInterface $now, int $limit): array;
}
