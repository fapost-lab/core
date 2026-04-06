<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Policies;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Models\Channel;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for {@see Channel}.
 *
 * Users can manage channels only when they manage assistants or are assigned to the channel's assistant.
 */
final class ChannelPolicy
{
    use HandlesAuthorization;

    /**
     * Whether the user can list channels.
     */
    public function viewAny(AuthUser $authUser): bool
    {
        return $this->canManageAssistants($authUser);
    }

    /**
     * Whether the user can view a specific channel.
     */
    public function view(AuthUser $authUser, Channel $channel): bool
    {
        return $this->canManageAssistants($authUser) && $this->canAccessAssistant($authUser, $channel->assistant);
    }

    /**
     * Create channel: when the second argument is omitted (e.g. generic Gate checks), only
     * {@see Permission::ManageAssistants} applies. When an {@see Assistant} is passed (nested
     * authorization), assignment must match {@see view()} / API expectations.
     */
    public function create(AuthUser $authUser, ?Assistant $assistant = null): bool
    {
        if ( ! $this->canManageAssistants($authUser)) {
            return false;
        }

        if (null === $assistant) {
            return true;
        }

        return $this->canAccessAssistant($authUser, $assistant);
    }

    /**
     * Whether the user can update a specific channel.
     */
    public function update(AuthUser $authUser, Channel $channel): bool
    {
        return $this->canManageAssistants($authUser) && $this->canAccessAssistant($authUser, $channel->assistant);
    }

    /**
     * Whether the user can delete a specific channel.
     */
    public function delete(AuthUser $authUser, Channel $channel): bool
    {
        return $this->canManageAssistants($authUser) && $this->canAccessAssistant($authUser, $channel->assistant);
    }

    /**
     * Whether the user can rotate webhook settings for a specific channel.
     */
    public function rotateWebhook(AuthUser $authUser, Channel $channel): bool
    {
        return $this->canManageAssistants($authUser) && $this->canAccessAssistant($authUser, $channel->assistant);
    }

    private function canManageAssistants(AuthUser $authUser): bool
    {
        return $authUser instanceof User
            && $authUser->can(Permission::ManageAssistants->value);
    }

    private function canAccessAssistant(AuthUser $authUser, ?Assistant $assistant): bool
    {
        if ( ! $authUser instanceof User || ! $assistant) {
            return false;
        }

        if ($authUser->isAdmin()) {
            return true;
        }

        return $authUser->assistants()->whereKey($assistant->getKey())->exists();
    }
}
