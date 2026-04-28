<?php

declare(strict_types=1);

namespace App\Domains\Flow\Support;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\MutableDataAccessorRegistryInterface;
use App\Domains\Flow\Exceptions\UnknownDataAccessorNamespacePrefixException;
use FAPost\Foundation\Contracts\DataAccessorInterface;
use LogicException;

final class ModuleDataAccessorRegistry implements DataAccessorRegistryInterface, MutableDataAccessorRegistryInterface
{
    private const array RESERVED_PREFIXES = ['flow', 'system', 'rag'];

    /** @var array<string, DataAccessorInterface> */
    private array $accessors = [];

    private bool $frozen = false;

    public function register(string $namespacePrefix, DataAccessorInterface $accessor): void
    {
        if ($this->frozen) {
            throw new LogicException('DataAccessorRegistry is frozen and cannot be modified.');
        }

        $rootPrefix = explode('.', $namespacePrefix)[0];
        if (in_array($rootPrefix, self::RESERVED_PREFIXES, true)) {
            throw new LogicException("Namespace prefix '{$namespacePrefix}' is reserved by engine.");
        }

        if ( ! str_starts_with($namespacePrefix, 'module.')) {
            throw new LogicException(
                "Namespace prefix '{$namespacePrefix}' must start with 'module.'."
            );
        }

        if (isset($this->accessors[$namespacePrefix])) {
            throw new LogicException("Duplicate data accessor prefix '{$namespacePrefix}'.");
        }

        $this->accessors[$namespacePrefix] = $accessor;
    }

    public function resolve(string $namespacePrefix): DataAccessorInterface
    {
        if ( ! $this->has($namespacePrefix)) {
            throw new UnknownDataAccessorNamespacePrefixException(
                "No data accessor registered for namespace prefix '{$namespacePrefix}'."
            );
        }

        return $this->accessors[$namespacePrefix];
    }

    public function has(string $namespacePrefix): bool
    {
        return isset($this->accessors[$namespacePrefix]);
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }
}
