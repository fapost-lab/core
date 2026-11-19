<?php

declare(strict_types=1);

namespace App\Domains\Flow\Repositories;

use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\Events\CoreEventCatalog;
use App\Domains\Flow\Models\TenantEvent;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant's event registry (`tenant_events`), read together with Core's events: the catalog's names come first and
 * are never stored, and a reserved name a flow registered before the reservation is not listed.
 */
final class TenantEventRepository implements TenantEventRepositoryInterface
{
    public function getEventNamesByTenant(string $tenantId): array
    {
        if (! Schema::hasTable('tenant_events')) {
            return CoreEventCatalog::names();
        }

        $flowEvents = TenantEvent::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('event_name')
            ->pluck('event_name')
            ->reject(static fn (string $name): bool => CoreEventCatalog::isReserved($name))
            ->values()
            ->all();

        return [...CoreEventCatalog::names(), ...$flowEvents];
    }

    public function registerEventNames(string $tenantId, array $eventNames): void
    {
        if (! Schema::hasTable('tenant_events')) {
            return;
        }

        foreach (array_unique($eventNames) as $eventName) {
            $name = mb_trim($eventName);
            if ('' === $name || CoreEventCatalog::isReserved($name)) {
                continue;
            }

            TenantEvent::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'event_name' => $name],
                [],
            );
        }
    }
}
