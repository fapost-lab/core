<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Jobs\SendActivationEmailJob;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a pending staff user, assigns a role, issues activation token, queues email.
 */
final class CreatePendingUserService
{
    public function __construct(
        private readonly ActivationTokenService $activationTokenService,
        private readonly TenantContextInterface $tenantContext,
    ) {
    }

    /**
     * @param  array{name: string, email: string, phone: ?string, role_id: int|string}  $data
     */
    public function create(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data): User {
            $user = User::query()->create([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'phone'    => $data['phone'] ?? null,
                'password' => null,
                'status'   => UserStatus::Pending,
            ]);

            $role = Role::query()->findOrFail($data['role_id']);
            $this->assertCanAssignRole($actor, $role);
            $user->assignRole($role);

            $plain = $this->activationTokenService->issue($user);

            SendActivationEmailJob::dispatch(
                $this->tenantContext->get()->getId(),
                $user->getKey(),
                $plain,
            );

            return $user;
        });
    }

    private function assertCanAssignRole(User $actor, Role $role): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        $actorMaxPriority = Role::maxPriority($actor->roles);

        if ($role->priority < $actorMaxPriority) {
            return;
        }

        throw ValidationException::withMessages([
            'role_id' => __('You are not allowed to assign this role.'),
        ]);
    }
}
