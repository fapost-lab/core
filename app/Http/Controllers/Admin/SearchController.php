<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Staff\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SearchRequest;
use App\Http\Shell\AdminSearch;
use Illuminate\Http\JsonResponse;
use LogicException;

/**
 * The admin shell's search palette: assistants, staff users, roles and media files the user may see, in place of
 * Filament's global search. A read that answers JSON; the route is throttled, since every keystroke may ask.
 */
final class SearchController extends Controller
{
    public function __invoke(SearchRequest $request, AdminSearch $search): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('The admin stack runs for a signed-in staff user.');
        }

        return response()->json(['groups' => $search->search($user, $request->text())]);
    }
}
