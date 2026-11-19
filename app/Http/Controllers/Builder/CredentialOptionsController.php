<?php

declare(strict_types=1);

namespace App\Http\Controllers\Builder;

use App\Domains\Flow\Models\FlowDraft;
use App\Http\Controllers\Controller;
use Fapost\Foundation\Credentials\Contracts\CredentialStoreInterface;
use Fapost\Foundation\Credentials\DTO\CredentialReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Supplies the builder's `call` node with the tenant's saved credentials — `{id, name, type}` rows for a dropdown.
 * Only the reference is sent: no setting, host or secret. Whoever may edit flow definitions may pick a credential for a
 * call, even without `ManageSettings`, the right the console screen needs to create one. Tenant-scoped by the store.
 */
final class CredentialOptionsController extends Controller
{
    public function index(CredentialStoreInterface $credentials): JsonResponse
    {
        Gate::authorize('viewAny', FlowDraft::class);

        return response()->json([
            'data' => array_map(
                static fn (CredentialReference $reference): array => [
                    'id'   => $reference->id,
                    'name' => $reference->name,
                    'type' => $reference->type,
                ],
                $credentials->references(),
            ),
        ]);
    }
}
