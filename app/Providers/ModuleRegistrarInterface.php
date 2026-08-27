<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * Contract for module/feature registrar hooks.
 *
 * Modules use this interface to register capabilities during bootstrapping without directly mutating
 * global runtime state.
 */
interface ModuleRegistrarInterface
{
    /**
     * Declaratively register module contributions (routes, handlers, etc.) into CoreRegistrar surfaces.
     */
    public function register(): void;

    /**
     * Execute late boot hooks after registries are built.
     */
    public function boot(): void;
}
