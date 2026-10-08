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
 * Everything that targets a concrete draft also requires access to the draft's assistant
 * ({@see User::hasAssistantAccess()}), so a permission alone never reaches another assistant's flows.
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
               && $authUser->can(Permission::ManageFlowDefinitions->value)
               && $this->canAccessAssistant($authUser, $draft);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageFlowDefinitions->value);
    }

    public function update(AuthUser $authUser, FlowDraft $draft): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageFlowDefinitions->value)
               && $this->canAccessAssistant($authUser, $draft);
    }

    public function delete(AuthUser $authUser, FlowDraft $draft): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageFlowDefinitions->value)
               && $this->canAccessAssistant($authUser, $draft);
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
               && $authUser->can(Permission::PublishFlow->value)
               && $this->canAccessAssistant($authUser, $draft);
    }

    private function canAccessAssistant(User $authUser, FlowDraft $draft): bool
    {
        $assistant = $draft->assistant;

        return null !== $assistant && $authUser->hasAssistantAccess($assistant);
    }
}
