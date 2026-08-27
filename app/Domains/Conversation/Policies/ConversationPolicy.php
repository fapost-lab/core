<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Policies;

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for {@see Conversation}.
 *
 * Transcripts are the most sensitive data the platform stores — every message a
 * contact ever sent, plus their media. Reading requires
 * {@see Permission::ViewConversations}; replying additionally requires
 * {@see Permission::ReplyConversations}, because sending puts words in the
 * assistant's mouth to a real person.
 *
 * Threads are never created or deleted by hand: they are an append-only log
 * owned by the capture pipeline, and contact deletion cascades them away. So
 * create/update/delete return false rather than consulting a permission.
 *
 * Note this is not an absolute guarantee: {@see \App\Providers\StaffServiceProvider}
 * installs a platform-wide {@code Gate::before} hook that short-circuits every
 * ability to true for the Admin role, so a Gate check for an admin never
 * reaches these methods. That bypass is deliberate and applies to all policies;
 * the denial here is what governs every non-admin, and the resource keeps
 * {@code canCreate()} false regardless of role.
 */
final class ConversationPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $this->canView($authUser);
    }

    public function view(AuthUser $authUser, Conversation $conversation): bool
    {
        return $this->canView($authUser);
    }

    /**
     * Sending a message into an existing thread from the operator inbox.
     */
    public function reply(AuthUser $authUser, Conversation $conversation): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ReplyConversations->value);
    }

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, Conversation $conversation): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, Conversation $conversation): bool
    {
        return false;
    }

    private function canView(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ViewConversations->value);
    }
}
