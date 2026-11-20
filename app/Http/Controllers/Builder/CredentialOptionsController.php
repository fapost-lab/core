<?php

declare(strict_types=1);

namespace App\Http\Controllers\Builder;

use App\Domains\Credentials\Models\Credential;
use App\Domains\Flow\Models\FlowDraft;
use App\Http\Controllers\Controller;
use Fapost\Foundation\Credentials\Contracts\CredentialStoreInterface;
use Fapost\Foundation\Credentials\DTO\CredentialReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Supplies the builder's `call` node with the tenant's saved credentials — `{id, name, type}` rows for a dropdown.
 * Only the reference is sent: no setting, host or secret. It needs the right to edit flow definitions and the right to
 * use credentials (`UseCredentials`), not the console screen's `ManageCredentials`: an author builds on a credential an
 * administrator created. Tenant-scoped by the store.
 */
final class CredentialOptionsController extends Controller
{
    public function index(CredentialStoreInterface $credentials): JsonResponse
    {
        Gate::authorize('viewAny', FlowDraft::class);
        Gate::authorize('use', Credential::class);

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
