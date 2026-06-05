<?php

declare(strict_types=1);

namespace App\Domains\Staff\Notifications;

use App\Domains\Staff\Enums\StaffNotifyTarget;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves the staff recipients of a `notify_staff` node from its target config.
 *
 * Supported modes (see {@see StaffNotifyTarget}): all staff assigned to the
 * current assistant, staff holding a given role, or an explicit user id list.
 * Only active, panel-eligible users are returned; the result is de-duplicated.
 */
final class StaffRecipientResolver
{
    /**
     * @param  array<string, mixed>  $config  The node config block.
     * @return Collection<int, User>
     */
    public function resolve(array $config, ?string $assistantId): Collection
    {
        $target = StaffNotifyTarget::tryFrom(is_string($config['target'] ?? null) ? $config['target'] : '')
            ?? StaffNotifyTarget::Assistant;

        $query = $this->activeStaff();

        return match ($target) {
            StaffNotifyTarget::Assistant => $this->forAssistant($query, $assistantId),
            StaffNotifyTarget::Role      => $this->forRole($query, $config),
            StaffNotifyTarget::Users     => $this->forUsers($query, $config),
        };
    }

    /**
     * Base query: only users who can actually receive (active + enabled).
     *
     * @return Builder<User>
     */
    private function activeStaff(): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->where('status', UserStatus::Active->value);
    }

    /**
     * @param  Builder<User>  $query
     * @return Collection<int, User>
     */
    private function forAssistant(Builder $query, ?string $assistantId): Collection
    {
        if (null === $assistantId) {
            return new Collection();
        }

        return $query
            ->whereHas('assistants', static fn (Builder $sub): Builder => $sub->whereKey($assistantId))
            ->get();
    }

    /**
     * @param  Builder<User>         $query
     * @param  array<string, mixed>  $config
     * @return Collection<int, User>
     */
    private function forRole(Builder $query, array $config): Collection
    {
        $role = is_string($config['role'] ?? null) ? mb_trim($config['role']) : '';

        if ('' === $role) {
            return new Collection();
        }

        return $query->role($role)->get();
    }

    /**
     * @param  Builder<User>         $query
     * @param  array<string, mixed>  $config
     * @return Collection<int, User>
     */
    private function forUsers(Builder $query, array $config): Collection
    {
        $ids = array_values(array_filter(
            is_array($config['user_ids'] ?? null) ? $config['user_ids'] : [],
            static fn (mixed $id): bool => is_string($id) && '' !== mb_trim($id),
        ));

        if ([] === $ids) {
            return new Collection();
        }

        return $query->whereKey($ids)->get();
    }
}
