<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Console\Platform\UnservedChannelsFinderInterface;

/**
 * For tests of `platform:update` that run without a database: no tenant has a channel nothing serves.
 */
final class NoUnservedChannels implements UnservedChannelsFinderInterface
{
    /**
     * {@inheritDoc}
     */
    public function find(): array
    {
        return [];
    }
}
