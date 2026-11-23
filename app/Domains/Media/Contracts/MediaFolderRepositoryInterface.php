<?php

declare(strict_types=1);

namespace App\Domains\Media\Contracts;

/**
 * Persistence-level coordination of the folder tree.
 */
interface MediaFolderRepositoryInterface
{
    /**
     * Takes the tenant's folder-tree lock. It is transaction-scoped: held until the surrounding transaction ends, so
     * it must be called inside one. A no-op where the driver already serialises writers (SQLite).
     */
    public function lockTree(string $tenantId): void;
}
