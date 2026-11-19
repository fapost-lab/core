<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Contact;

use App\Domains\Contact\Events\ContactTagAdded;
use App\Domains\Contact\Events\ContactTagRemoved;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Repositories\ContactTagRepository;
use Illuminate\Support\Facades\Event;
use Tests\Feature\FeatureTestCase;

final class ContactTagEventsTest extends FeatureTestCase
{
    private ContactTagRepository $tags;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeEvents();
    }

    public function test_adding_a_new_tag_announces_it_once_and_an_existing_tag_announces_nothing(): void
    {
        $contact = Contact::factory()->create();

        $this->assertTrue($this->tags->add($contact->id, 'vip', 'session-1'));
        $this->assertFalse($this->tags->add($contact->id, 'vip', 'session-1'));

        Event::assertDispatchedTimes(ContactTagAdded::class, 1);
        Event::assertDispatched(ContactTagAdded::class, fn (ContactTagAdded $event): bool => (string) $contact->tenant_id === $event->tenantId
            && $contact->id === $event->contactId
            && 'vip' === $event->tag
            && 'session-1' === $event->taggedBy);
        Event::assertNotDispatched(ContactTagRemoved::class);
    }

    public function test_removing_announces_only_a_tag_that_was_there(): void
    {
        $contact = Contact::factory()->create();
        $this->tags->add($contact->id, 'vip', null);

        $this->assertFalse($this->tags->remove($contact->id, 'missing'));
        $this->assertTrue($this->tags->remove($contact->id, 'vip'));

        Event::assertDispatchedTimes(ContactTagRemoved::class, 1);
        Event::assertDispatched(ContactTagRemoved::class, fn (ContactTagRemoved $event): bool => (string) $contact->tenant_id === $event->tenantId
            && $contact->id === $event->contactId
            && 'vip' === $event->tag);
    }

    public function test_toggle_announces_exactly_one_event_per_call(): void
    {
        $contact = Contact::factory()->create();

        $this->assertTrue($this->tags->toggle($contact->id, 'lead', null));
        Event::assertDispatchedTimes(ContactTagAdded::class, 1);
        Event::assertNotDispatched(ContactTagRemoved::class);

        $this->assertFalse($this->tags->toggle($contact->id, 'lead', null));
        Event::assertDispatchedTimes(ContactTagAdded::class, 1);
        Event::assertDispatchedTimes(ContactTagRemoved::class, 1);
    }

    public function test_sync_announces_only_the_difference(): void
    {
        $contact = Contact::factory()->create();
        $this->tags->add($contact->id, 'old', null);
        $this->tags->add($contact->id, 'keep', null);
        $this->fakeEvents();

        $this->tags->syncForContact($contact->id, ['keep', 'new'], 'staff-1');

        Event::assertDispatchedTimes(ContactTagAdded::class, 1);
        Event::assertDispatched(ContactTagAdded::class, fn (ContactTagAdded $event): bool => 'new' === $event->tag && 'staff-1' === $event->taggedBy);
        Event::assertDispatchedTimes(ContactTagRemoved::class, 1);
        Event::assertDispatched(ContactTagRemoved::class, fn (ContactTagRemoved $event): bool => 'old' === $event->tag);
    }

    /**
     * The repository keeps the dispatcher it was built with, so it is resolved again after each fake.
     */
    private function fakeEvents(): void
    {
        Event::fake([ContactTagAdded::class, ContactTagRemoved::class]);
        $this->app->forgetInstance(ContactTagRepository::class);
        $this->tags = $this->app->make(ContactTagRepository::class);
    }
}
