<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Onboarding\Enums\CoreOnboardingStep;
use App\Domains\Onboarding\Services\OnboardingState;
use App\Domains\Onboarding\Services\OnboardingWizard;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\CreatePendingUserService;
use App\Domains\Staff\Services\StaffUserService;
use App\Http\Controllers\Concerns\RedirectsWizard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffUserRequest;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The optional team step of the setup wizard (`admin/onboarding/team`): invite staff with the invitation form of the
 * users screen and {@see CreatePendingUserService}.
 *
 * Props of `Console/Onboarding/Team`:
 *   onboarding: { steps: list<{key: string, required: bool, complete: bool, current: bool}> }
 *   roles:      list<{value: string, label: string}>   the roles the user may give
 *   complete:   bool     someone besides the person setting up has been invited, so the way on reads "Continue"
 *   later:      string   where to do this later, worded with the labels of the menu
 *   urls:       { submit: string, skip: string }
 *   backUrl:    string|null   the previous optional step, null for the first
 *
 * An invitation keeps the person on the page, so another can follow. Like the channel step it runs after the required
 * ones, and without the right to invite or at the staff limit it is not shown (the store answers 403 without the right). Skipping is {@see OnboardingController::continue()} and writes nothing.
 */
final class OnboardingTeamController extends Controller
{
    use RedirectsWizard;

    private const string STEP = 'team';

    public function __construct(
        private readonly StaffUserService $users,
        private readonly OnboardingState $state,
        private readonly OnboardingWizard $wizard,
    ) {
    }

    public function create(Request $request): Response|RedirectResponse
    {
        if ($this->state->isPending()) {
            return redirect()->route('console.admin.onboarding.show');
        }

        // As in the channel step: a user who may not invite is passed on; the store still refuses them.
        if (Gate::denies('create', User::class) || $this->users->limit()->reached) {
            return $this->redirectOnward(self::STEP);
        }

        /** @var User $actor */
        $actor = $request->user();
        $steps = $this->wizard->stepProps(CoreOnboardingStep::Team->value);

        return Inertia::render('Console/Onboarding/Team', [
            'onboarding' => ['steps' => $steps],
            'roles'      => $this->users->assignableRoles($actor)
                ->map(static fn (Role $role): array => ['value' => (string) $role->getKey(), 'label' => $role->title])
                ->values()
                ->all(),
            'complete' => collect($steps)->contains(static fn (array $step): bool => CoreOnboardingStep::Team->value === $step['key'] && $step['complete']),
            'later'    => trans('console.onboarding.team.later', [
                'path' => trans('staff.navigation.group') . ' › ' . trans('staff.users.plural_label'),
            ]),
            'urls' => [
                'submit' => route('console.admin.onboarding.team.store', [], false),
                'skip'   => route('console.admin.onboarding.continue', ['step' => self::STEP], false),
            ],
            'backUrl' => $this->backUrl(CoreOnboardingStep::Team->value),
        ]);
    }

    public function store(StoreStaffUserRequest $request, CreatePendingUserService $invitations): RedirectResponse
    {
        if ($this->state->isPending()) {
            return redirect()->route('console.admin.onboarding.show');
        }

        /** @var User $actor */
        $actor = $request->user();

        try {
            $invitations->create($actor, $request->fields());
        } catch (RecordLimitReachedException $exception) {
            Inertia::flash('error', trans('staff.users.limit.reached_title') . '. ' . $exception->getMessage());

            return $this->redirectOnward(self::STEP, false);
        }

        Inertia::flash('success', trans('console.users.invited'));

        return redirect()->route('console.admin.onboarding.team.create');
    }
}
