<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Media\Models\MediaBlob;
use App\Domains\Tenancy\Contracts\TenantContextInterface;

/**
 * How many bytes of media the current tenant stores: the one definition behind both the upload
 * gate and the usage report, so "why it was refused" and "what the operator sees" cannot differ.
 *
 * Counts physical bytes after deduplication, from every source, including blobs whose files are
 * all in the trash (the bytes stay on disk until the file is deleted permanently).
 */
final readonly class StoredMediaBytes
{
    public function __construct(private TenantContextInterface $context)
    {
    }

    public function current(): int
    {
        return (int) MediaBlob::query()
            ->where('tenant_id', $this->context->get()->getId())
            ->sum('size');
    }
}
