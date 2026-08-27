<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Domains\Media\Models\MediaFile;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\Media\MediaFileReferenceResource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Lists incremental media_file_references for a single file. Backed by the table
 * maintained by {@see \App\Domains\Media\Listeners\TrackFlowDefinitionMediaReferences}
 * — never scans flow_definitions JSONB at request time.
 */
final class ReferencesController extends Controller
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
    ) {
    }

    public function index(string $fileId): AnonymousResourceCollection
    {
        $tenantId = $this->tenantContext->get()->getId();

        $file = MediaFile::query()
            ->withTrashed()
            ->where('tenant_id', $tenantId)
            ->whereKey($fileId)
            ->first();

        if (null === $file) {
            throw new AuthorizationException();
        }

        $this->authorize('view', $file);

        $references = $file->references()->orderByDesc('created_at')->get();

        return MediaFileReferenceResource::collection($references);
    }
}
