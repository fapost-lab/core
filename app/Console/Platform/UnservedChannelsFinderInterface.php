<?php

declare(strict_types=1);

namespace App\Console\Platform;

/**
 * Finds channels whose type no installed integration serves: the connector that provided it was removed.
 */
interface UnservedChannelsFinderInterface
{
    /**
     * @return list<array{tenant: string, type: string, count: int}> one entry per tenant and type, tenants in the
     *                                                              order of the tenant list
     */
    public function find(): array;
}
