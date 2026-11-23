<?php

declare(strict_types=1);

namespace App\Http\Controllers\Builder;

use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Staff\Enums\BuilderMode;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\BuilderModePreference;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBuilderPreferenceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Stores the builder mode (tree or graph) the signed-in staff user chose, per person, so every flow opens in it; a switch
 * to the other mode is announced to operator packages ({@see BuilderModePreference}).
 * A stopped tenant refuses the write through the route group's write guard; the builder keeps the choice locally.
 */
final class BuilderPreferenceController extends Controller
{
    public function update(UpdateBuilderPreferenceRequest $request, BuilderModePreference $preference): JsonResponse
    {
        Gate::authorize('viewAny', FlowDraft::class);

        $mode = BuilderMode::from($request->validated('builder_mode'));

        /** @var User $user */
        $user = $request->user();
        $preference->choose($user, $mode);

        return response()->json(['builder_mode' => $mode->value]);
    }
}
