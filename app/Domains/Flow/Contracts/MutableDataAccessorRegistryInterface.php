<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use Fapost\Foundation\Contracts\DataAccessorInterface;

interface MutableDataAccessorRegistryInterface
{
    public function register(string $namespacePrefix, DataAccessorInterface $accessor): void;
}
