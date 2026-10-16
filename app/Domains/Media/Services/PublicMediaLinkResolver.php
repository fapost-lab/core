<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Media\DTO\PublicMediaLinkClaims;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Storage\TenantMediaDisk;
use App\Domains\Tenancy\Contracts\TenantInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether a public media link opens, and which blob it opens.
 *
 * The order is the contract: the cheap checks that need nothing from storage come first, the file is looked up only
 * inside the tenant of the request (and never in the trash), the signature is checked before the disk is touched.
 * Every refusal is one answer for the caller (null) and one warning without the URL or the signature.
 */
final readonly class PublicMediaLinkResolver
{
    /**
     * Seconds the ceiling check forgives: the worker that issued a link for the whole maximum lifetime and the web
     * process that checks it read two clocks, and a provider may fetch in the same second.
     */
    private const int CLOCK_SKEW_SECONDS = 60;

    public function __construct(
        private PublicMediaLinkSigner $signer,
        private TenantMediaDisk $disks,
    ) {
    }

    public function resolve(TenantInterface $tenant, string $fileId, int $expires, string $signature, string $segment): ?MediaBlob
    {
        $now = CarbonImmutable::now()->getTimestamp();

        if ($expires < $now) {
            return $this->refuse($tenant, $fileId, 'expired');
        }

        if ($expires > $now + (int)config('media.public_links.max_ttl_seconds', 86400) + self::CLOCK_SKEW_SECONDS) {
            return $this->refuse($tenant, $fileId, 'too_far');
        }

        $file = MediaFile::query()
            ->where('tenant_id', $tenant->getId())
            ->whereKey($fileId)
            ->with('blob')
            ->first();

        if (! $file instanceof MediaFile || null === $file->blob) {
            return $this->refuse($tenant, $fileId, 'missing');
        }

        $blob = $file->blob;

        $claims = new PublicMediaLinkClaims(
            tenantId: $tenant->getId(),
            fileId: $file->id,
            blobId: $blob->id,
            expires: $expires,
            segment: $segment,
        );

        if (! $this->signer->verify($claims, $signature)) {
            return $this->refuse($tenant, $fileId, 'signature');
        }

        if (! $this->disks->resolve($tenant)->exists($blob->storage_path)) {
            return $this->refuse($tenant, $fileId, 'object_missing');
        }

        return $blob;
    }

    private function refuse(TenantInterface $tenant, string $fileId, string $reason): null
    {
        Log::warning('media.public_link.refused', [
            'file_id'   => $fileId,
            'tenant_id' => $tenant->getId(),
            'reason'    => $reason,
        ]);

        return null;
    }
}
