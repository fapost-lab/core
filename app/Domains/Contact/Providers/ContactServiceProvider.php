<?php

declare(strict_types=1);

namespace App\Domains\Contact\Providers;

use App\Domains\Contact\Contracts\ContactServiceInterface;
use App\Domains\Contact\Services\ContactService;
use Illuminate\Support\ServiceProvider;

/**
 * Contact bounded context provider.
 *
 * Registers the contact domain service implementation behind {@see ContactServiceInterface}.
 */
final class ContactServiceProvider extends ServiceProvider
{
    /**
     * Bind the contact service contract to its default implementation.
     */
    public function register(): void
    {
        $this->app->bind(ContactServiceInterface::class, ContactService::class);
    }
}
