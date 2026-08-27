<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use Fapost\Foundation\Contracts\DataAccessorInterface;

interface DataAccessorRegistryInterface
{
    public function has(string $namespacePrefix): bool;

    public function resolve(string $namespacePrefix): DataAccessorInterface;
}
