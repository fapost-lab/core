<?php

declare(strict_types=1);

namespace App\Domains\Flow\Repositories;

use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\Models\TenantEvent;
use Illuminate\Support\Facades\Schema;

final class TenantEventRepository implements TenantEventRepositoryInterface
{
    public function getEventNamesByTenant(string $tenantId): array
    {
        if (! Schema::hasTable('tenant_events')) {
            return [];
        }

        return TenantEvent::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('event_name')
            ->pluck('event_name')
            ->all();
    }

    public function registerEventNames(string $tenantId, array $eventNames): void
    {
        if (! Schema::hasTable('tenant_events')) {
            return;
        }

        foreach (array_unique($eventNames) as $eventName) {
            $name = mb_trim($eventName);
            if ('' === $name) {
                continue;
            }

            TenantEvent::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'event_name' => $name],
                [],
            );
        }
    }
}
