<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Staff\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Console\ApplicationRailRequest;
use App\Http\Shell\ApplicationRail;
use Illuminate\Http\RedirectResponse;
use LogicException;

/**
 * Stores which applications the signed-in user keeps in the shell's rail and in what order. The request allows only
 * applications the user may open; the page goes back where it was, and the shell redraws the rail.
 */
final class ApplicationRailController extends Controller
{
    public function __invoke(ApplicationRailRequest $request, ApplicationRail $rail): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('The admin stack runs for a signed-in staff user.');
        }

        $rail->save($user, $request->keys(), $request->applications());

        return back();
    }
}
