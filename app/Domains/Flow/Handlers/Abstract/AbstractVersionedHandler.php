<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers\Abstract;

use App\Domains\Flow\Contracts\NodeHandlerInterface;

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
