<?php

declare(strict_types=1);

namespace App\Http\Controllers\Builder;

use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Supplies the builder's `notify` node (staff mode) with selectable staff
 * users — `{value: id, label: name}` pairs for a searchable dropdown. Only
 * active, panel-eligible users are listed. Tenant-scoped via the active schema.
 */
final class StaffOptionsController extends Controller
{
    public function index(): JsonResponse
    {
        $options = User::query()
            ->where('is_active', true)
            ->where('status', UserStatus::Active->value)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (User $user): array => [
                'value' => (string) $user->getKey(),
                'label' => (string) $user->name,
            ])
            ->all();

        return response()->json(['data' => $options]);
    }
}
