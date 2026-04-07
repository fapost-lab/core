<?php

declare(strict_types=1);

namespace App\Domains\Shared\Contracts;

interface DataAccessorInterface
{
    public function get(string $path): mixed;

    public function set(string $path, mixed $value): void;
}
