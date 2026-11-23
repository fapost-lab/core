<?php

declare(strict_types=1);

namespace App\Http\Controllers\Builder;

use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Supplies the builder's `send_message` node (addressee "segment") with the tenant's contact segments — `{value: id,
 * label: name}` pairs, by name. Read-only.
 */
final class SegmentOptionsController extends Controller
{
    public function index(TenantContextInterface $tenants): JsonResponse
    {
        Gate::authorize('viewAny', FlowDraft::class);

        $options = ContactSegment::query()
            ->where('tenant_id', $tenants->get()->getId())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (ContactSegment $segment): array => [
                'value' => (string) $segment->getKey(),
                'label' => (string) $segment->name,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $options]);
    }
}
