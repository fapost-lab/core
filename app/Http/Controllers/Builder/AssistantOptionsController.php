<?php

declare(strict_types=1);

namespace App\Http\Controllers\Builder;

use App\Domains\Assistant\Models\Assistant;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Supplies the builder's `notify` node (contacts mode) with selectable target
 * assistants — `{value: id, label: name}` pairs for a searchable dropdown.
 * Tenant-scoped via the active schema (assistants live in the tenant database).
 */
final class AssistantOptionsController extends Controller
{
    public function index(): JsonResponse
    {
        $options = Assistant::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Assistant $assistant): array => [
                'value' => (string) $assistant->getKey(),
                'label' => (string) $assistant->name,
            ])
            ->all();

        return response()->json(['data' => $options]);
    }
}
