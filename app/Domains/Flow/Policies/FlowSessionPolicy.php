<?php

declare(strict_types=1);

namespace App\Domains\Flow\Policies;

use App\Domains\Flow\Models\FlowSession;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for {@see FlowSession}.
 *
 * Sessions are engine-owned and read-only in the UI — only
 * {@see Permission::ViewFlowSessions} is required; mutations are denied. The full node input and output of a run's
 * trace ({@see self::viewPayloads()}) needs {@see Permission::ViewRunPayloads} as well, and re-running a run from the
 * start ({@see self::rerun()}) needs {@see Permission::RerunFlow}: it starts a new run and leaves the session as it is.
 */
final class FlowSessionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $this->canView($authUser);
    }

    public function view(AuthUser $authUser, FlowSession $session): bool
    {
        return $this->canView($authUser);
    }

    /**
     * Whether the user may read the full input and output of the nodes in the session's trace: what outside services
     * answered, contact data included. No screen shows them yet: the run graph page draws statuses only.
     */
    public function viewPayloads(AuthUser $authUser, FlowSession $session): bool
    {
        return $this->canView($authUser)
               && $authUser->can(Permission::ViewRunPayloads->value);
    }

    /**
     * Whether the user may start the session's run again from the start, with the trigger payload it started with. Which
     * runs can be re-run is the rerun service's to say; this asks only who may.
     */
    public function rerun(AuthUser $authUser, FlowSession $session): bool
    {
        return $this->canView($authUser)
               && $authUser->can(Permission::RerunFlow->value);
    }

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, FlowSession $session): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, FlowSession $session): bool
    {
        return false;
    }

    private function canView(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ViewFlowSessions->value);
    }
}
