<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Registers Staff domain bindings (minimal for Task 05; extend here later).
 */
final class StaffDomainServiceProvider extends ServiceProvider
{
    /**
     * Register additional staff domain bindings (currently empty stub).
     */
    public function register(): void
    {
    }

    /**
     * Bootstrap staff domain services (currently empty stub).
     */
    public function boot(): void
    {
    }
}
