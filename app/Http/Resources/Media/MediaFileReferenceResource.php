<?php

declare(strict_types=1);

namespace App\Http\Resources\Media;

use App\Domains\Media\Models\MediaFileReference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MediaFileReference
 */
final class MediaFileReferenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'reference_type' => $this->reference_type,
            'reference_id'   => $this->reference_id,
            'snapshot'       => $this->snapshot,
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
