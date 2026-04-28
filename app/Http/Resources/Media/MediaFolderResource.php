<?php

declare(strict_types=1);

namespace App\Http\Resources\Media;

use App\Domains\Media\Models\MediaFolder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MediaFolder
 */
final class MediaFolderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'parent_id'  => $this->parent_id,
            'name'       => $this->name,
            'path_cache' => $this->path_cache,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Optional fields populated by specific endpoints (tree, picker).
            'breadcrumbs'         => $this->additional['breadcrumbs'] ?? null,
            'file_count_total'    => $this->additional['file_count_total'] ?? null,
            'file_count_filtered' => $this->additional['file_count_filtered'] ?? null,
        ];
    }
}
