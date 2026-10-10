<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Assistant\Services\StaffAssistantService;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;

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
     * @return array{total: int, active: int}
     */
    public function channels(): array
    {
        return [
            'total'  => Channel::query()->where('tenant_id', $this->tenantId())->count(),
            'active' => Channel::query()->where('tenant_id', $this->tenantId())->where('is_active', true)->count(),
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
