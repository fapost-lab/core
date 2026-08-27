<?php

declare(strict_types=1);

namespace App\Http\Controllers\Builder;

use App\Domains\Contact\Contracts\ContactTagRepositoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Supplies the builder's `set_tag` node with the tenant's existing tag
 * vocabulary for autocomplete. Read-only; tenant-scoped via the active schema.
 */
final class ContactTagsController extends Controller
{
    public function index(ContactTagRepositoryInterface $tags): JsonResponse
    {
        return response()->json(['data' => $tags->distinctTags()]);
    }
}
