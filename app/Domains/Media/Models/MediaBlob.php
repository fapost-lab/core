<?php

declare(strict_types=1);

namespace App\Domains\Media\Models;

use App\Domains\Media\Storage\TenantMediaDisk;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Media\MediaBlobReadInterface;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Physical media file deduplicated by (tenant_id, content_hash).
 *
 * One MediaBlob may back many MediaFile rows. Channel uploads cache against blob_id,
 * not media_file_id, so duplicate user files share a single channel upload.
 *
 * @property string                          $id
 * @property string                          $tenant_id
 * @property string                          $content_hash
 * @property string                          $storage_path
 * @property string                          $storage_disk
 * @property int                             $size
 * @property string                          $mime_type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class MediaBlob extends BaseModel implements MediaBlobReadInterface
{
    use HasUlidPrimaryKey;

    protected $table = 'media_blobs';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'content_hash',
        'storage_path',
        'storage_disk',
        'size',
        'mime_type',
    ];

    /**
     * @return HasMany<MediaFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(MediaFile::class, 'blob_id');
    }

    /**
     * @return HasMany<MediaChannelRef, $this>
     */
    public function channelRefs(): HasMany
    {
        return $this->hasMany(MediaChannelRef::class, 'blob_id');
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getContentHash(): string
    {
        return $this->content_hash;
    }

    public function getMimeType(): string
    {
        return $this->mime_type;
    }

    public function getSize(): int
    {
        return (int)$this->size;
    }

    public function openStream(): StreamInterface
    {
        $tenant = app(TenantContextInterface::class)->get();
        $disk   = app(TenantMediaDisk::class)->resolve($tenant);

        $resource = $disk->readStream($this->storage_path);

        if (false === $resource || null === $resource) {
            throw new RuntimeException(sprintf('Unable to open stream for media blob [%s].', $this->id));
        }

        return Utils::streamFor($resource);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }
}
