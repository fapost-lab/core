<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The assistants of the current platform tenant that a user may open, ordered by name.
 *
 * One list for every surface that switches between assistants: the Filament panel's tenant menu
 * ({@see User::getTenants()}) and the Inertia console's switcher.
 */
final readonly class AccessibleAssistants
{
    public function __construct(
        private TenantContextInterface $tenantContext,
    ) {
    }

    /**
     * @return Collection<int, Assistant>
     */
    public function for(User $user): Collection
    {
        return Assistant::query()
            ->where('tenant_id', $this->tenantContext->get()->getId())
            ->orderBy('name')
            ->get()
            ->filter(fn (Assistant $assistant): bool => Gate::forUser($user)->allows('view', $assistant))
            ->values();
    }
}
