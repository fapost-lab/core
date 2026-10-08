<?php

declare(strict_types=1);

namespace App\Domains\Channels\Policies;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for {@see Channel}.
 *
 * Users can manage channels only when they hold {@see Permission::ManageChannels} and are assigned to the
 * channel's assistant. {@see Permission::ManageAssistants} alone grants no channel access: a channel carries
 * the messenger's credentials. Rotating the webhook hash is gated separately by
 * {@see Permission::RotateChannelToken}.
 */
final class ChannelPolicy
{
    use HandlesAuthorization;

    /**
     * Whether the user can list channels.
     */
    public function viewAny(AuthUser $authUser): bool
    {
        return $this->canManageChannels($authUser);
    }

    /**
     * Whether the user can view a specific channel.
     */
    public function view(AuthUser $authUser, Channel $channel): bool
    {
        return $this->canManageChannels($authUser) && $this->canAccessAssistant($authUser, $channel->assistant);
    }

    /**
     * Create channel: when the second argument is omitted (e.g. generic Gate checks), only
     * {@see Permission::ManageChannels} applies. When an {@see Assistant} is passed (nested
     * authorization), assignment must match {@see view()} / API expectations.
     */
    public function create(AuthUser $authUser, ?Assistant $assistant = null): bool
    {
        if (! $this->canManageChannels($authUser)) {
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
        return $this->canManageChannels($authUser) && $this->canAccessAssistant($authUser, $channel->assistant);
    }

    /**
     * Whether the user can delete a specific channel.
     */
    public function delete(AuthUser $authUser, Channel $channel): bool
    {
        return $this->canManageChannels($authUser) && $this->canAccessAssistant($authUser, $channel->assistant);
    }

    /**
     * Whether the user can rotate the webhook hash of a specific channel.
     *
     * Rotation needs the sensitive {@see Permission::RotateChannelToken}, not
     * {@see Permission::ManageChannels}: a role that manages channels does not
     * get to cut the messenger off from the webhook unless it is granted this.
     */
    public function rotateWebhook(AuthUser $authUser, Channel $channel): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::RotateChannelToken->value)
               && $this->canAccessAssistant($authUser, $channel->assistant);
    }

    private function canManageChannels(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageChannels->value);
    }

    private function canAccessAssistant(AuthUser $authUser, ?Assistant $assistant): bool
    {
        return $authUser instanceof User
               && null !== $assistant
               && $authUser->hasAssistantAccess($assistant);
    }
}
