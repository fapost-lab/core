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
use App\Domains\Contact\Services\InboundContactGate;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\DTO\LimitDefinition;
use Fapost\Foundation\Quota\Enums\LimitKind;
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
     * Register contact authorization policies and the active-contact limit key.
     */
    public function boot(): void
    {
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(ContactGroup::class, ContactGroupPolicy::class);
        Gate::policy(ContactSegment::class, ContactSegmentPolicy::class);

        $this->app->make(LimitRegistryInterface::class)->register(new LimitDefinition(
            key: InboundContactGate::LIMIT_KEY,
            label: 'Monthly active contacts',
            unit: 'contacts',
            kind: LimitKind::PerPeriod,
            description: 'Distinct contacts that sent at least one inbound message in the period. A contact over the limit is not answered.',
        ));
    }
}
