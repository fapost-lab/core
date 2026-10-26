<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Onboarding\Services\OnboardingChecklist;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Hides the setup checklist (`admin/onboarding/checklist/hide`) for the signed-in user.
 *
 * The user's choice is the step keys registered now, so the checklist comes back by itself when a step is registered
 * that was not there. It hides both the shell's indicator and the dashboard widget. The checklist itself is a shared
 * prop (`onboardingChecklist`, see {@see \App\Http\Middleware\HandleInertiaRequests}), not a page of its own.
 */
final class OnboardingChecklistController extends Controller
{
    public function __construct(private readonly OnboardingChecklist $checklist)
    {
    }

    public function hide(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettings->value);

        /** @var User $user */
        $user = $request->user();

        $user->forceFill(['onboarding_checklist_hidden' => $this->checklist->registeredKeys()])->save();

        return back();
    }
}
