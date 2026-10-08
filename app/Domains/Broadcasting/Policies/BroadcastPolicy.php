<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Policies;

use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for {@see Broadcast}.
 *
 * Every ability requires {@see Permission::ManageBroadcast}. Abilities on a concrete broadcast
 * additionally require access to the broadcast's assistant ({@see User::hasAssistantAccess()}):
 * sending and cancelling reach real contacts through that assistant's channels.
 */
final class BroadcastPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $this->canManage($authUser);
    }

    public function view(AuthUser $authUser, Broadcast $broadcast): bool
    {
        return $this->canManageFor($authUser, $broadcast);
    }

    public function create(AuthUser $authUser): bool
    {
        return $this->canManage($authUser);
    }

    public function update(AuthUser $authUser, Broadcast $broadcast): bool
    {
        return $this->canManageFor($authUser, $broadcast);
    }

    public function delete(AuthUser $authUser, Broadcast $broadcast): bool
    {
        return $this->canManageFor($authUser, $broadcast);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $this->canManage($authUser);
    }

    /**
     * Whether the user can start a draft broadcast.
     */
    public function send(AuthUser $authUser, Broadcast $broadcast): bool
    {
        return $this->canManageFor($authUser, $broadcast);
    }

    /**
     * Whether the user can cancel a running broadcast.
     */
    public function cancel(AuthUser $authUser, Broadcast $broadcast): bool
    {
        return $this->canManageFor($authUser, $broadcast);
    }

    private function canManage(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageBroadcast->value);
    }

    private function canManageFor(AuthUser $authUser, Broadcast $broadcast): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageBroadcast->value)
               && $authUser->hasAssistantAccess($broadcast->assistant);
    }
}
