<?php

declare(strict_types=1);

namespace App\Providers;

interface ModuleRegistrarInterface
{
    public function register(): void;

    public function boot(): void;
}
