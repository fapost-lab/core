<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Exceptions\StaffChangeRefusedException;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Support\StaffLimitStatus;
use Closure;
use Fapost\Foundation\Quota\Contracts\RecordQuotaInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Staff users as the admin panel lists, edits and removes them. Invitations go through {@see CreatePendingUserService},
 * activation and deactivation through {@see UserService}.
 *
 * The users table lives in the tenant's schema, so every query here is the current tenant's. What must stop an
 * administrator too (they pass every policy through `Gate::before`) is checked here, not in a policy: nobody changes
 * their own roles or removes their own account, the last active administrator keeps the account and the administrator
 * role, and the platform support user is left alone.
 *
 * Which roles one may give: an administrator gives and takes away any staff role, the administrator role included;
 * everyone else only roles below their own highest priority, and the roles of the target above that stay as they are.
 */
final readonly class StaffUserService
{
    /**
     * The guard staff roles belong to, as the Filament form filters them.
     */
    private const string STAFF_GUARD = 'web';

    public function __construct(
        private RecordQuotaInterface $recordQuota,
        private UserService $users,
    ) {
    }

    /**
     * @return Builder<User>
     */
    public function query(): Builder
    {
        return User::query()->with('roles');
    }

    /**
     * A user of the current tenant; anything else, a malformed id included, is not found.
     */
    public function find(string $id): User
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(User::class, [$id]);
        }

        return $this->query()->whereKey($id)->firstOrFail();
    }

    public function limit(): StaffLimitStatus
    {
        $current = User::countForLimit();

        return new StaffLimitStatus(
            current: $current,
            limit: $this->recordQuota->limit(User::LIMIT_KEY),
            reached: ! $this->recordQuota->canCreate(User::LIMIT_KEY, $current),
        );
    }

    /**
     * The staff roles the actor may give and take away, highest priority first: every staff role for an administrator,
     * otherwise those strictly below the actor's highest priority.
     *
     * @return Collection<int, Role>
     */
    public function assignableRoles(User $actor): Collection
    {
        return Role::query()
            ->where('guard_name', self::STAFF_GUARD)
            ->when(! $actor->isAdmin(), static fn (Builder $query) => $query->where('priority', '<', Role::maxPriority($actor->roles)))
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();
    }

    /**
     * Saves the profile and, when `$roleIds` is given, the roles, in one transaction.
     *
     * @param  array{name: string, email: string, phone: string|null, password: string|null}  $profile  an empty
     *         password keeps the stored one
     * @param  list<string>|null  $roleIds  null leaves the roles as they are
     *
     * @throws StaffChangeRefusedException when the actor changes their own roles, takes the administrator role from the
     *         last active administrator, or touches the platform support user
     * @throws ValidationException when a role is outside what the actor may give
     */
    public function update(User $actor, User $target, array $profile, ?array $roleIds): User
    {
        if ($target->isPlatformSupport()) {
            throw new StaffChangeRefusedException(StaffChangeRefusedException::PLATFORM_SUPPORT);
        }

        return DB::transaction(function () use ($actor, $target, $profile, $roleIds): User {
            $target->fill([
                'name'  => $profile['name'],
                'email' => $profile['email'],
                'phone' => $profile['phone'],
            ]);

            if (null !== $profile['password'] && '' !== $profile['password']) {
                $target->password = $profile['password'];
            }

            $target->save();

            if (null !== $roleIds) {
                $this->syncRoles($actor, $target, $roleIds);
            }

            return $target->refresh();
        });
    }

    /**
     * The check and the delete share a transaction that locks the active administrators' rows, so two administrators
     * deleting each other at once cannot both succeed.
     *
     * @throws StaffChangeRefusedException for the actor's own account, the last active administrator or the platform
     *         support user
     */
    public function delete(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target): void {
            $this->assertDeletable($actor, $target);

            $target->delete();
        });
    }

    /**
     * Deletes the listed users one by one: each must pass `$allowed` (the caller's authorization of that record) and
     * the guards of {@see delete()}; the others are skipped and counted. Unknown ids are ignored.
     *
     * Record by record, never one query: a bulk delete would skip the model's `deleting` guard of the support user.
     *
     * @param  list<string>             $ids
     * @param  Closure(User): bool      $allowed
     *
     * @return array{deleted: int, blocked: int}
     */
    public function deleteMany(User $actor, array $ids, Closure $allowed): array
    {
        $ids    = array_values(array_filter($ids, static fn (string $id): bool => Str::isUuid($id)));
        $result = ['deleted' => 0, 'blocked' => 0];

        if ([] === $ids) {
            return $result;
        }

        foreach ($this->query()->whereKey($ids)->get() as $user) {
            if (! $allowed($user)) {
                ++$result['blocked'];

                continue;
            }

            try {
                $this->delete($actor, $user);
                ++$result['deleted'];
            } catch (StaffChangeRefusedException) {
                ++$result['blocked'];
            }
        }

        return $result;
    }

    /**
     * The roles the actor may give replace the ones the actor may give; the target's other roles stay as they are.
     * One's own roles are not changed here: the same set sent back (the form saved as it was opened) is no change and
     * passes, anything else is refused. Taking the administrator role from the last active administrator is refused.
     *
     * @param  list<string>  $roleIds
     */
    private function syncRoles(User $actor, User $target, array $roleIds): void
    {
        $assignable   = $this->assignableRoles($actor);
        $assignableId = $assignable->map(static fn (Role $role): string => (string) $role->getKey())->all();
        $roleIds      = array_values(array_unique($roleIds));

        if ([] !== array_diff($roleIds, $assignableId)) {
            throw ValidationException::withMessages(['roles' => __('You are not allowed to assign this role.')]);
        }

        $requested = $assignable->filter(static fn (Role $role): bool => in_array((string) $role->getKey(), $roleIds, true));
        $kept      = $target->roles->reject(static fn (Role $role): bool => in_array((string) $role->getKey(), $assignableId, true));
        $current   = $target->roles->filter(static fn (Role $role): bool => in_array((string) $role->getKey(), $assignableId, true));

        if ($actor->is($target)) {
            if ($this->sameRoles($current, $requested)) {
                return;
            }

            throw new StaffChangeRefusedException(StaffChangeRefusedException::OWN_ROLES);
        }

        $losesAdmin = $current->contains(static fn (Role $role): bool => $role->isAdminRole())
            && ! $requested->contains(static fn (Role $role): bool => $role->isAdminRole());

        if ($losesAdmin && $this->users->isLastActiveAdmin($target)) {
            throw new StaffChangeRefusedException(StaffChangeRefusedException::LAST_ADMIN);
        }

        $target->syncRoles($kept->concat($requested)->unique(static fn (Role $role): string => (string) $role->getKey())->values()->all());
    }

    /**
     * @param  SupportCollection<int, Role>  $left
     * @param  SupportCollection<int, Role>  $right
     */
    private function sameRoles(SupportCollection $left, SupportCollection $right): bool
    {
        $ids = static fn (SupportCollection $roles): array => $roles->map(static fn (Role $role): string => (string) $role->getKey())->sort()->values()->all();

        return $ids($left) === $ids($right);
    }

    private function assertDeletable(User $actor, User $target): void
    {
        if ($target->isPlatformSupport()) {
            throw new StaffChangeRefusedException(StaffChangeRefusedException::PLATFORM_SUPPORT);
        }

        if ($actor->is($target)) {
            throw new StaffChangeRefusedException(StaffChangeRefusedException::OWN_ACCOUNT);
        }

        if ($this->users->isLastActiveAdmin($target)) {
            throw new StaffChangeRefusedException(StaffChangeRefusedException::LAST_ADMIN);
        }
    }
}
