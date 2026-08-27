<?php

declare(strict_types=1);

namespace App\Domains\Media\Models;

use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-managed folder tree for organizing media files.
 *
 * Stored as DB rows (not filesystem paths) so rename/move is a single transactional UPDATE.
 * `path_cache` is a denormalized "/projects/onboarding"-style breadcrumb maintained by the
 * service layer to avoid N+1 walks of the parent chain.
 *
 * @property string                          $id
 * @property string                          $tenant_id
 * @property string|null                     $parent_id
 * @property string                          $name
 * @property string                          $path_cache
 * @property string|null                     $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read MediaFolder|null           $parent
 */
final class MediaFolder extends BaseModel
{
    use HasUlidPrimaryKey;

    protected $table = 'media_folders';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'parent_id',
        'name',
        'path_cache',
        'created_by',
    ];

    /**
     * @return BelongsTo<MediaFolder, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<MediaFolder, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<MediaFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(MediaFile::class, 'folder_id');
    }
}
