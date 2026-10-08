<?php

declare(strict_types=1);

namespace App\Domains\Contact\Providers;

use App\Domains\Contact\Contracts\ContactServiceInterface;
use App\Domains\Contact\Contracts\ContactTagRepositoryInterface;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Contact\Policies\ContactGroupPolicy;
use App\Domains\Contact\Policies\ContactPolicy;
use App\Domains\Contact\Policies\ContactSegmentPolicy;
use App\Domains\Contact\Repositories\ContactTagRepository;
use App\Domains\Contact\Services\ContactService;
use Illuminate\Support\Facades\Gate;
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
        $this->app->bind(ContactTagRepositoryInterface::class, ContactTagRepository::class);
    }

    /**
     * Register contact authorization policies.
     */
    public function boot(): void
    {
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(ContactGroup::class, ContactGroupPolicy::class);
        Gate::policy(ContactSegment::class, ContactSegmentPolicy::class);
    }
}
