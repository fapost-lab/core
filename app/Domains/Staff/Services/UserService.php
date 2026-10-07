<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Fapost\Foundation\Quota\Contracts\RecordQuotaInterface;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff user lifecycle operations: deactivation and reactivation.
 */
final class UserService
{
    public function __construct(
        private readonly RecordQuotaInterface $recordQuota,
    ) {
    }

    /**
     * Deactivate a staff account. Immediately invalidates sessions and blocks login.
     *
     * @throws ValidationException when invariants are violated
     */
    public function deactivate(User $actor, User $target): void
    {
        $this->assertCanChangeStatus($actor, $target, 'deactivate');

        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'user' => __('You cannot deactivate your own account.'),
            ]);
        }

        if ($this->isLastActiveAdmin($target)) {
            throw ValidationException::withMessages([
                'user' => __('Cannot deactivate the last active administrator account.'),
            ]);
        }

        DB::transaction(function () use ($target): void {
            $target->update([
                'is_active'      => false,
                'remember_token' => null,
            ]);

            // Invalidate all active sessions for this user.
            DB::table('sessions')->where('user_id', $target->getKey())->delete();
        });
    }

    /**
     * Reactivate a previously deactivated account.
     * A new login is required — sessions are not restored.
     *
     * A deactivated account does not take a staff place, so reactivating it needs a free one.
     *
     * @throws RecordLimitReachedException when the tenant is at its staff limit
     */
    public function activate(User $actor, User $target): void
    {
        $this->assertCanChangeStatus($actor, $target, 'activate');

        if (! $target->is_active) {
            $this->recordQuota->assertCanCreate(User::LIMIT_KEY, User::countForLimit());
        }

        $target->update(['is_active' => true]);
    }

    private function assertCanChangeStatus(User $actor, User $target, string $ability): void
    {
        // The Gate refuses this for admins too; the service is also reached without it.
        if ($target->isPlatformSupport()) {
            throw ValidationException::withMessages([
                'user' => __('staff.support_access.protected'),
            ]);
        }

        if ($actor->isAdmin()) {
            return;
        }

        throw ValidationException::withMessages([
            'user' => __('You are not allowed to change user activation status.'),
        ]);
    }

    /**
     * Returns true when $target is the only active admin left in the tenant.
     * The platform support user is an admin too, but it is not one of the tenant's people.
     */
    private function isLastActiveAdmin(User $target): bool
    {
        if (! $target->isAdmin()) {
            return false;
        }

        $activeAdminCount = User::query()
            ->withoutPlatformSupport()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', RoleEnum::Admin->value))
            ->count();

        return $activeAdminCount <= 1;
    }
}
