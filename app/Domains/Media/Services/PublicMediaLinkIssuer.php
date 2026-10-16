<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Media\DTO\PublicMediaLink;
use App\Domains\Media\DTO\PublicMediaLinkClaims;
use App\Domains\Media\Exceptions\MediaDeletedException;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Support\TenantHost;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Media\Enums\MediaKind;
use LogicException;

/**
 * Mints the public link of a media file for a channel that fetches media by URL.
 *
 * The only class that signs a link ({@see PublicMediaLinkSigner}). The link lives for the requested time, never
 * longer than `media.public_links.max_ttl_seconds` and never shorter than a minute; the same ceiling is applied
 * again when a link is opened, so a mistake here cannot make a link last for ever.
 *
 * The URL is spelled out on the tenant's own host ({@see TenantHost::urlFor()}): this runs in a queue worker, where
 * `url()` names the base domain.
 */
final readonly class PublicMediaLinkIssuer
{
    public const int MIN_TTL_SECONDS = 60;

    public function __construct(
        private PublicMediaLinkSigner $signer,
        private PublicMediaExtension $extension,
    ) {
    }

    /**
     * @throws MediaDeletedException when the file is in the trash
     * @throws LogicException when the file belongs to another tenant
     */
    public function issue(MediaFile $file, TenantInterface $tenant, ?int $requestedTtlSeconds): PublicMediaLink
    {
        if (null !== $file->deleted_at) {
            throw MediaDeletedException::forId($file->id);
        }

        if ($file->tenant_id !== $tenant->getId()) {
            throw new LogicException(sprintf('Media file [%s] does not belong to tenant [%s].', $file->id, $tenant->getId()));
        }

        $blob    = $file->blob;
        $segment = $this->extension->segmentFor($file->name, $blob->mime_type);
        $ttl     = max(self::MIN_TTL_SECONDS, min(
            $requestedTtlSeconds ?? (int)config('media.public_links.ttl_seconds', 3600),
            (int)config('media.public_links.max_ttl_seconds', 86400),
        ));
        $expiresAt = CarbonImmutable::now()->addSeconds($ttl);
        $signature = $this->signer->sign(new PublicMediaLinkClaims(
            tenantId: $tenant->getId(),
            fileId: $file->id,
            blobId: $blob->id,
            expires: $expiresAt->getTimestamp(),
            segment: $segment,
        ));

        return new PublicMediaLink(
            url: TenantHost::urlFor($tenant, sprintf('media/public/%s/%d/%s/%s', $file->id, $expiresAt->getTimestamp(), $signature, $segment)),
            expiresAt: $expiresAt,
            mimeType: $blob->mime_type,
            size: $blob->size,
            fileName: $segment,
            kind: MediaKind::fromMimeType($blob->mime_type),
        );
    }
}
