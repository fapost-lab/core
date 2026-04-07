<?php

declare(strict_types=1);

namespace App\Domains\Shared\Registries;

use App\Domains\Shared\Contracts\DataAccessorInterface;
use LogicException;

final class ModuleNamespaceRegistry
{
    /** @var array<string, DataAccessorInterface> */
    private array $accessors = [];
    private bool $frozen     = false;

    public function register(string $owner, DataAccessorInterface $accessor): void
    {
        if ($this->frozen) {
            throw new LogicException('ModuleNamespaceRegistry is frozen and cannot be modified.');
        }

        $this->accessors[$owner] = $accessor;
    }

    public function for(string $owner): DataAccessorInterface
    {
        if ( ! isset($this->accessors[$owner])) {
            throw new LogicException("No accessor registered for module namespace '{$owner}'");
        }

        return $this->accessors[$owner];
    }

    public function has(string $owner): bool
    {
        return isset($this->accessors[$owner]);
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }
}
