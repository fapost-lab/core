<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers\Abstract;

use FAPost\Foundation\Contracts\NodeHandlerInterface;

abstract class AbstractVersionedHandler implements NodeHandlerInterface
{
    /**
     * @return array<int>
     */
    public function supportedVersions(): array
    {
        return [$this->version()];
    }
}
