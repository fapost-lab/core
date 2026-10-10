<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Services\StaffAssistantService;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The tenant's record counts on the admin dashboard, the ones Filament's `StatsOverview` showed (the flow figures are
 * {@see \App\Domains\Flow\Services\TenantFlowActivity}'s). Which of them a user sees is the controller's decision.
 *
 * Every count is bounded by the current tenant explicitly: the console has no Filament tenancy scope.
 */
final readonly class AdminOverview
{
    public function __construct(
        private TenantContextInterface $tenants,
        private StaffAssistantService $assistants,
    ) {
    }

    /**
     * The assistants as the user's list shows them: all of them for an administrator, the assigned ones otherwise.
     *
     * @return array{total: int, active: int}
     */
    public function assistants(User $user): array
    {
        return [
            'total'  => $this->assistants->query($user)->count(),
            'active' => $this->assistants->query($user)->where('is_active', true)->count(),
        ];
    }

    /**
     * @return array{total: int}
     */
    public function contacts(): array
    {
        return ['total' => Contact::query()->where('tenant_id', $this->tenantId())->count()];
    }

    /**
     * The assistants whose figures a user sees: `null` (the whole tenant) for an administrator, otherwise the ones their
     * list shows, so channels, flows and sessions of an assistant they may not open are not counted for them.
     *
     * @return Builder<Assistant>|null
     */
    public function assistantScope(User $user): ?Builder
    {
        return $user->isAdmin() ? null : $this->assistants->query($user);
    }

    /**
     * @param  Builder<Assistant>|null  $assistants  the assistants to count for; `null` is the whole tenant
     *
     * @return array{total: int, active: int}
     */
    public function channels(?Builder $assistants = null): array
    {
        $query = Channel::query()->where('tenant_id', $this->tenantId());

        if (null !== $assistants) {
            $query->whereIn('assistant_id', (clone $assistants)->select('assistants.id'));
        }

        return [
            'total'  => (clone $query)->count(),
            'active' => $query->where('is_active', true)->count(),
        ];
    }

    /**
     * The tenant's people: the users table is the tenant's schema, and the platform support user is not counted.
     *
     * @return array{total: int}
     */
    public function staff(): array
    {
        return ['total' => User::query()->withoutPlatformSupport()->count()];
    }

    private function tenantId(): string
    {
        return $this->tenants->get()->getId();
    }
}
