<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Events;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Jobs\Flow\StartFlowFromEventJob;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * Covers the event-triggered flow start: the resolved trigger's flow is started
 * for the emitting contact, with the event payload exposed under `flow.event.*`.
 */
final class StartFlowFromEventJobTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_starts_the_subscribed_flow_with_event_payload_in_state(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();

        $flowId = (string) Str::uuid();
        FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => $flowId,
            'version'   => 1,
            'name'      => 'Event Flow',
            'nodes'     => [
                ['id' => 'n-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges'     => [],
            'is_active' => true,
        ]);

        $job = new StartFlowFromEventJob(
            tenantId: self::TENANT_ID,
            flowId: $flowId,
            assistantId: (string) $assistant->getKey(),
            contactId: (string) $contact->getKey(),
            payload: ['amount' => 42, 'currency' => 'USD'],
            eventName: 'order.created',
        );

        app()->call([$job, 'handle']);

        /** @var FlowSession $session */
        $session = FlowSession::query()
            ->where('contact_id', $contact->getKey())
            ->latest('created_at')
            ->first();

        $this->assertNotNull($session, 'The subscribed flow should have started a session.');
        $this->assertSame((string) $assistant->getKey(), (string) $session->assistant_id);
        $this->assertSame(FlowSessionStatus::Ended, $session->status);
        $this->assertSame(EndStatus::Success->value, $session->end_status);
        $this->assertSame(['amount' => 42, 'currency' => 'USD'], $session->state['flow']['event'] ?? null);
    }

    public function test_does_nothing_when_flow_is_not_active(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();

        $job = new StartFlowFromEventJob(
            tenantId: self::TENANT_ID,
            flowId: (string) Str::uuid(),            // no active definition for this flow id
            assistantId: (string) $assistant->getKey(),
            contactId: (string) $contact->getKey(),
            payload: [],
            eventName: 'order.created',
        );

        app()->call([$job, 'handle']);

        $this->assertSame(
            0,
            FlowSession::query()->where('contact_id', $contact->getKey())->count(),
            'No session should be created when the flow has no active definition.',
        );
    }
}
