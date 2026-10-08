<?php

declare(strict_types=1);

namespace App\Domains\Media\Models;

use App\Domains\Media\Enums\MediaSource;
use Fapost\Foundation\Media\Enums\MediaKind;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * User-visible media entity (name, folder, metadata) backed by a deduplicated MediaBlob.
 *
 * Soft-deletes are required so an active flow_session can fail through a meaningful
 * MediaDeletedException instead of crashing on a missing storage_path.
 *
 * @property string                          $id
 * @property string                          $tenant_id
 * @property string                          $blob_id
 * @property string|null                     $folder_id
 * @property string                          $name
 * @property MediaKind                       $kind
 * @property array<string, mixed>            $metadata
 * @property string|null                     $uploaded_by
 * @property MediaSource                     $source
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read MediaBlob                  $blob
 * @property-read MediaFolder|null           $folder
 */
final class MediaFile extends BaseModel
{
    use HasUlidPrimaryKey;
    use SoftDeletes;

    /** @var string */
    protected $table = 'media_files';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'blob_id',
        'folder_id',
        'name',
        'kind',
        'metadata',
        'uploaded_by',
        'source',
    ];

    /**
     * @return BelongsTo<MediaBlob, $this>
     */
    public function blob(): BelongsTo
    {
        return $this->belongsTo(MediaBlob::class, 'blob_id');
    }

    /**
     * @return BelongsTo<MediaFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    /**
     * @return HasMany<MediaFileReference, $this>
     */
    public function references(): HasMany
    {
        return $this->hasMany(MediaFileReference::class, 'media_file_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind'     => MediaKind::class,
            'source'   => MediaSource::class,
            'metadata' => 'array',
        ];
    }
}
