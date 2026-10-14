<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Staff\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SearchRequest;
use App\Http\Shell\AdminSearch;
use App\Http\Shell\ApplicationRegistry;
use App\Http\Shell\ApplicationSearch;
use Illuminate\Http\JsonResponse;
use LogicException;

/**
 * The shell's search palette (⌘K) in both panels: the applications and their pages the user may open, then the
 * assistants, staff users, roles and media files the user may see, in place of Filament's global search. A read that
 * answers JSON; the route is throttled, since every keystroke may ask.
 */
final class SearchController extends Controller
{
    public function __invoke(SearchRequest $request, ApplicationSearch $applications, AdminSearch $records): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('The admin stack runs for a signed-in staff user.');
        }

        $text = $request->text();
        $last = $request->session()->get(ApplicationRegistry::LAST_ASSISTANT);

        // The asking tab's assistant first (checked like any other), the one last worked in as the fallback.
        return response()->json(['groups' => [
            ...$applications->search($user, $text, [$request->assistant(), is_string($last) ? $last : null]),
            ...$records->search($user, $text),
        ]]);
    }
}
