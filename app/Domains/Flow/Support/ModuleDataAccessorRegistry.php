<?php

declare(strict_types=1);

namespace App\Domains\Flow\Support;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Shared\Registries\ModuleNamespaceRegistry;
use InvalidArgumentException;

final readonly class ModuleDataAccessorRegistry implements DataAccessorRegistryInterface
{
    public function __construct(
        private ModuleNamespaceRegistry $registry,
    ) {
    }

    public function resolve(string $path, string $contactId, string $tenantId): mixed
    {
        $segments = explode('.', $path, 3);

        if (3 !== count($segments) || 'module' !== $segments[0]) {
            throw new InvalidArgumentException("Invalid module path '{$path}'.");
        }

        [, $owner, $key] = $segments;

        return $this->registry->for($owner)->get($key, $contactId, $tenantId);
    }
}
