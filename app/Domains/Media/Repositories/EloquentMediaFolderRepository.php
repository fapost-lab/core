<?php

declare(strict_types=1);

namespace App\Domains\Media\Repositories;

use App\Domains\Media\Contracts\MediaFolderRepositoryInterface;
use App\Domains\Media\Models\MediaFolder;

/**
 * Eloquent implementation of MediaFolderRepositoryInterface: the lock is a PostgreSQL transaction-scoped advisory
 * lock keyed on the tenant, taken on the connection the folders live on.
 */
final class EloquentMediaFolderRepository implements MediaFolderRepositoryInterface
{
    public function lockTree(string $tenantId): void
    {
        $connection = (new MediaFolder())->getConnection();

        if ('pgsql' === $connection->getDriverName()) {
            $connection->select('select pg_advisory_xact_lock(hashtext(?))', ['media-folder-tree:' . $tenantId]);
        }
    }
}
