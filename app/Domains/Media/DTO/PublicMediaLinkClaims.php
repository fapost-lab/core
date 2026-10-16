<?php

declare(strict_types=1);

namespace App\Domains\Media\DTO;

/**
 * The six values a public media link signature covers.
 *
 * Only `fileId`, `expires` and `segment` are visible in the URL. The tenant and the blob are known to the issuer when
 * it signs and to the verifier from the request context and the file row, so a link replayed on another tenant's host
 * or after the file's blob changed does not verify.
 */
final readonly class PublicMediaLinkClaims
{
    public function __construct(
        public string $tenantId,
        public string $fileId,
        public string $blobId,
        public int $expires,
        public string $segment,
    ) {
    }

    public function message(): string
    {
        return implode("\n", ['v1', $this->tenantId, $this->fileId, $this->blobId, (string)$this->expires, $this->segment]);
    }
}
