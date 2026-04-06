<?php

declare(strict_types=1);

namespace App\Domains\Flow\Providers;

use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Validation\FlowDefinitionValidator;
use Illuminate\Support\ServiceProvider;

final class FlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NodeHandlerRegistry::class);
        $this->app->singleton(
            NodeHandlerRegistryInterface::class,
            fn ($app): NodeHandlerRegistry => $app->make(NodeHandlerRegistry::class)
        );
        $this->app->singleton(FlowDefinitionValidator::class);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->app->make(NodeHandlerRegistryInterface::class)->freeze();
        });
    }
}
