<?php

declare(strict_types=1);

namespace App\Domains\Media\Models;

use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Incremental cross-reference between a media file and an entity that points at it
 * (currently only flow_definition; broadcast_template will follow in a later task).
 *
 * Maintained synchronously on entity save so soft-delete of a media file can answer
 * "where is this used?" via a SELECT instead of scanning JSONB across the tenant.
 *
 * @property string                     $id
 * @property string                     $media_file_id
 * @property string                     $reference_type
 * @property string                     $reference_id
 * @property array<string, mixed>       $snapshot
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read MediaFile             $mediaFile
 */
final class MediaFileReference extends BaseModel
{
    use HasUlidPrimaryKey;

    public const UPDATED_AT = null;

    public const TYPE_FLOW_DEFINITION = 'flow_definition';

    protected $table = 'media_file_references';

    /** @var list<string> */
    protected $fillable = [
        'media_file_id',
        'reference_type',
        'reference_id',
        'snapshot',
    ];

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_file_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }
}
