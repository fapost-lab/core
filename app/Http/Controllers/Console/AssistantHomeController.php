<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\AccessibleAssistants;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The two addresses of the assistant console that are not screens but may be bookmarked: `assistant` and
 * `assistant/{assistant}`. Both send on to a dashboard, as Filament's assistant panel did.
 */
final class AssistantHomeController extends Controller
{
    /**
     * `assistant`: the dashboard of the first assistant the user may open (by name, as the switcher lists them), or the
     * admin console when there is none. Filament answered 404 there; the admin console is where such a user works.
     */
    public function index(Request $request, AccessibleAssistants $assistants): RedirectResponse
    {
        /** @var User $user */
        $user  = $request->user();
        $first = $assistants->for($user)->first();

        // The list is already filtered by the same policy; asked again here as defence in depth, and so the action carries
        // its own check (ConsoleAuthorizationTest).
        if (null === $first || Gate::denies('view', $first)) {
            return redirect()->route('console.admin.dashboard');
        }

        return redirect()->route('console.dashboard', ['assistant' => (string) $first->getKey()]);
    }

    /**
     * `assistant/{assistant}`: that assistant's dashboard. The `console` stack has already answered 404 for an assistant
     * the user may not open.
     */
    public function show(CurrentAssistantInterface $currentAssistant): RedirectResponse
    {
        $assistant = $currentAssistant->get();

        Gate::authorize('view', $assistant);

        return redirect()->route('console.dashboard', ['assistant' => (string) $assistant->getKey()]);
    }
}
