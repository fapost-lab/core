<?php

declare(strict_types=1);

namespace App\Domains\Media\Repositories;

use App\Domains\Media\Contracts\MediaBlobRepositoryInterface;
use App\Domains\Media\Models\MediaBlob;
use Illuminate\Database\QueryException;

/**
 * Eloquent implementation of MediaBlobRepositoryInterface.
 *
 * Race safety relies on the (tenant_id, content_hash) UNIQUE constraint: when two
 * concurrent uploaders try to create the same blob, the loser's INSERT fails and is
 * resolved by re-querying the row created by the winner.
 */
final class EloquentMediaBlobRepository implements MediaBlobRepositoryInterface
{
    public function findByContentHash(string $tenantId, string $contentHash): ?MediaBlob
    {
        return MediaBlob::query()
            ->where('tenant_id', $tenantId)
            ->where('content_hash', $contentHash)
            ->first();
    }

    public function createOrFindByContentHash(string $tenantId, string $contentHash, array $attributes): MediaBlob
    {
        $existing = $this->findByContentHash($tenantId, $contentHash);

        if (null !== $existing) {
            return $existing;
        }

        try {
            return MediaBlob::query()->create(
                array_merge(
                    $attributes,
                    [
                        'tenant_id'    => $tenantId,
                        'content_hash' => $contentHash,
                    ],
                )
            );
        } catch (QueryException $exception) {
            // Race condition: another writer just inserted the same (tenant_id, content_hash).
            // SQLSTATE 23xxx covers integrity violations across Postgres/SQLite/MySQL.
            $row = $this->findByContentHash($tenantId, $contentHash);

            if (null === $row) {
                throw $exception;
            }

            return $row;
        }
    }
}
