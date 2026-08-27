<?php

declare(strict_types=1);

namespace App\Http\Resources\Media;

use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Preview\MediaPreviewRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MediaFile
 */
final class MediaFileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $blob      = $this->blob;
        $signedUrl = app(MediaServiceInterface::class)->signedUrl($this->resource);

        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'kind'        => $this->kind->value,
            'source'      => $this->source->value,
            'folder_id'   => $this->folder_id,
            'mime_type'   => $blob?->mime_type,
            'size'        => $blob?->size,
            'metadata'    => $this->metadata,
            'created_at'  => $this->created_at?->toIso8601String(),
            'updated_at'  => $this->updated_at?->toIso8601String(),
            'deleted_at'  => $this->deleted_at?->toIso8601String(),
            'preview_url' => $signedUrl,
            'preview'     => $this->buildPreviewMetadata($signedUrl),

            // Computed at upload time only — see FilesController::store().
            'deduplicated' => $this->additional['deduplicated'] ?? null,
        ];
    }

    /**
     * Inline preview descriptor consumed by the Vue picker — kind plus signed URL plus
     * Heroicon name. `requires_external_render` flags previews (e.g. PDFs) the picker
     * cannot render with native HTML elements and must route through a server-side iframe.
     *
     * @return array<string, mixed>
     */
    private function buildPreviewMetadata(?string $signedUrl): array
    {
        $type = app(MediaPreviewRegistry::class)->resolve($this->resource);

        if (null === $type) {
            return [
                'kind'                     => 'other',
                'icon_heroicon'            => 'document',
                'signed_url'               => $signedUrl,
                'requires_external_render' => false,
            ];
        }

        return [
            'kind'                     => $type->kind,
            'icon_heroicon'            => $type->iconHeroicon,
            'signed_url'               => $signedUrl,
            'requires_external_render' => $type->requiresExternalRender,
        ];
    }
}
