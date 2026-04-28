<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Services;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Assistants the current user may operate on in the assistant panel (same rules as
 * {@see \App\Domains\Assistant\Policies\AssistantPolicy} view scope).
 */
final class AssistantSwitchListService
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
    ) {
    }

    /**
     * @return Collection<int, Assistant>
     */
    public function forUser(User $user): Collection
    {
        $tenantId = $this->tenantContext->get()->getId();
        $query    = Assistant::query()->where('tenant_id', $tenantId);

        if ( ! $user->isAdmin()) {
            $query->whereHas(
                'users',
                fn (Builder $q): Builder => $q->whereKey($user->getKey()),
            );
        }

        return $query->orderBy('name')->get();
    }
}
