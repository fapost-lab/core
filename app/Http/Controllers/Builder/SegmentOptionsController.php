<?php

declare(strict_types=1);

namespace App\Http\Controllers\Builder;

use App\Domains\Contact\Services\ContactSegmentService;
use App\Domains\Flow\Models\FlowDraft;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Supplies the builder's `send_message` node (addressee "segment") with the tenant's contact segments — `{value: id,
 * label: name}` pairs, by name. Read-only.
 */
final class SegmentOptionsController extends Controller
{
    public function index(ContactSegmentService $segments): JsonResponse
    {
        Gate::authorize('viewAny', FlowDraft::class);

        return response()->json(['data' => $segments->options()]);
    }
}
