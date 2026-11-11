<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Services;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who may work with an assistant's threads: the one check behind the transfer targets of the inbox, the recipients of
 * an operator request and the operator notifications.
 *
 * Reading a thread needs `ViewConversations` and access to its assistant (assigned to it, or an administrator), as
 * {@see \App\Domains\Conversation\Policies\ConversationPolicy} decides; taking one also needs `ReplyConversations`.
 * Only active staff who may use the console count; the platform support user never does.
 */
final readonly class ConversationOperators
{
    /**
     * Active, panel-eligible staff who work with the assistant (assigned to it, or administrators).
     *
     * @return Collection<int, User>
     */
    public function eligibleStaff(Assistant $assistant): Collection
    {
        return User::query()
            ->withoutPlatformSupport()
            ->where('is_active', true)
            ->where('status', UserStatus::Active->value)
            ->where(static fn (Builder $query) => $query
                ->whereHas('assistants', static fn (Builder $sub): Builder => $sub->whereKey($assistant->getKey()))
                ->orWhereHas('roles', static fn (Builder $sub): Builder => $sub->where('name', RoleEnum::Admin->value)))
            ->get()
            ->toBase();
    }

    /**
     * Whether the user may accept, answer and finish the assistant's threads.
     */
    public function canTakeThreads(Assistant $assistant, User $user): bool
    {
        return $this->canReadThreads($assistant, $user)
            && $user->can(Permission::ReplyConversations->value);
    }

    /**
     * Whether the user may open the assistant's threads: what a link to a thread in their bell needs.
     */
    public function canReadThreads(Assistant $assistant, User $user): bool
    {
        return $user->canAccessConsole()
            && ! $user->isPlatformSupport()
            && $user->can(Permission::ViewConversations->value)
            && $user->hasAssistantAccess($assistant);
    }

    /**
     * Those of `$candidates` who may take the assistant's threads, de-duplicated.
     *
     * @param  iterable<User>  $candidates
     *
     * @return Collection<int, User>
     */
    public function whoCanTake(Assistant $assistant, iterable $candidates): Collection
    {
        return collect($candidates)
            ->filter(fn (mixed $user): bool => $user instanceof User && $this->canTakeThreads($assistant, $user))
            ->unique(static fn (User $user): string => (string) $user->getKey())
            ->values();
    }
}
