<?php

declare(strict_types=1);

namespace App\Domains\Flow\Policies;

use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for {@see FlowDraft}.
 *
 * Authoring drafts requires {@see Permission::ManageFlowDefinitions}.
 * Publishing requires the additional {@see Permission::PublishFlow} gate.
 */
final class FlowDraftPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageFlowDefinitions->value);
    }

    public function view(AuthUser $authUser, FlowDraft $draft): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageFlowDefinitions->value);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageFlowDefinitions->value);
    }

    public function update(AuthUser $authUser, FlowDraft $draft): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageFlowDefinitions->value);
    }

    public function delete(AuthUser $authUser, FlowDraft $draft): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageFlowDefinitions->value);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageFlowDefinitions->value);
    }

    /**
     * Whether the user can publish a flow draft to an active definition.
     */
    public function publish(AuthUser $authUser, FlowDraft $draft): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::PublishFlow->value);
    }
}
