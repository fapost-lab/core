<?php

declare(strict_types=1);

namespace App\Domains\Flow\Policies;

use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Services\FlowPublishChanges;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for {@see FlowDraft}.
 *
 * Authoring drafts requires {@see Permission::ManageFlowDefinitions}.
 * Publishing requires the additional {@see Permission::PublishFlow} gate, and what a publish changes may need more
 * ({@see self::publishChanges()}): a new saved credential needs {@see Permission::UseCredentials}, a changed automatic
 * trigger {@see Permission::ManageAutomations}. Turning on a flow with an automatic trigger needs the latter too.
 * Everything that targets a concrete draft also requires access to the draft's assistant
 * ({@see User::hasAssistantAccess()}), so a permission alone never reaches another assistant's flows.
 */
final class FlowDraftPolicy
{
    use HandlesAuthorization;

    public function __construct(
        private readonly FlowPublishChanges $changes,
    ) {
    }

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

    /**
     * Whether the user may publish what the draft changes against what runs now, on top of {@see self::publish()}:
     * a `call` node bound to a saved credential the published version does not use needs the right to use credentials;
     * adding, changing or removing a trigger other than `message` needs the right to manage automations. Removing a
     * credential reference, and editing nodes, need neither.
     */
    public function publishChanges(AuthUser $authUser, FlowDraft $draft): Response
    {
        if (! $authUser instanceof User) {
            return Response::deny();
        }

        if (! $authUser->can(Permission::UseCredentials->value) && [] !== $this->changes->addedCredentialIds($draft)) {
            return Response::deny(__('builder.publish_denied.credentials'));
        }

        if (! $authUser->can(Permission::ManageAutomations->value) && $this->changes->changesAutomations($draft)) {
            return Response::deny(__('builder.publish_denied.automations'));
        }

        return Response::allow();
    }

    /**
     * Whether the user may hand an outside system the means to start the flow, such as a webhook trigger's secret: what
     * publishing an automatic trigger takes, {@see self::publish()} and the right to manage automations.
     */
    public function manageAutomations(AuthUser $authUser, FlowDraft $draft): bool
    {
        return $this->publish($authUser, $draft)
               && $authUser->can(Permission::ManageAutomations->value);
    }

    /**
     * Whether the user may turn the flow on. A flow with a live trigger other than `message` starts runs on its own once
     * on, so that needs the right to manage automations as well as {@see self::update()}. Turning a flow off needs only
     * `update`.
     */
    public function activate(AuthUser $authUser, FlowDraft $draft): bool
    {
        return $this->update($authUser, $draft)
               && ($authUser->can(Permission::ManageAutomations->value) || ! $this->changes->hasLiveAutomation($draft));
    }

    private function canAccessAssistant(User $authUser, FlowDraft $draft): bool
    {
        $assistant = $draft->assistant;

        return null !== $assistant && $authUser->hasAssistantAccess($assistant);
    }
}
