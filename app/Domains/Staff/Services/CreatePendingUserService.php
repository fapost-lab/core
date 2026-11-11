<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditChanges;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Jobs\SendActivationEmailJob;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Services\RecordLimitWatch;
use Fapost\Foundation\Quota\Contracts\RecordQuotaInterface;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a pending staff user, assigns a role, issues activation token, queues email. The invitation is recorded in the
 * audit log with the name, email and role; never the activation token.
 */
final class CreatePendingUserService
{
    public function __construct(
        private readonly ActivationTokenService $activationTokenService,
        private readonly TenantContextInterface $tenantContext,
        private readonly RecordQuotaInterface $recordQuota,
        private readonly RecordLimitWatch $limitWatch,
        private readonly AuditTrailInterface $audit,
    ) {
    }

    /**
     * An invited user takes a staff place at once, so the tenant's staff limit is checked here.
     *
     * @param  array{name: string, email: string, phone: ?string, role_id: int|string}  $data
     *
     * @throws RecordLimitReachedException when the tenant is at its staff limit
     */
    public function create(User $actor, array $data): User
    {
        if (PlatformSupportUserService::isReservedEmail($data['email'])) {
            throw ValidationException::withMessages(['email' => __('staff.support_access.reserved_email')]);
        }

        $countBefore = User::countForLimit();

        $this->recordQuota->assertCanCreate(User::LIMIT_KEY, $countBefore);

        return DB::transaction(function () use ($actor, $data, $countBefore): User {
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

            $this->audit->record(new AuditRecord(
                action: AuditAction::UserInvited,
                subject: $user,
                subjectLabel: $user->name,
                changes: AuditChanges::created([
                    'name'  => $user->name,
                    'email' => $user->email,
                    'roles' => [$role->name],
                ]),
            ));

            $plain = $this->activationTokenService->issue($user);

            SendActivationEmailJob::dispatch(
                $this->tenantContext->get()->getId(),
                $user->getKey(),
                $plain,
            );

            // Waits for this transaction to commit: a rolled-back invitation fills nothing.
            $this->limitWatch->afterSaved(User::LIMIT_KEY, $countBefore);

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
