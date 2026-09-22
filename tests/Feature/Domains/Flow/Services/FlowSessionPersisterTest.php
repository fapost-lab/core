<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Services;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\FlowSessionPersister;
use App\Domains\Flow\State\SystemStateNamespacePolicy;
use DateTimeInterface;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * {@see FlowSessionPersister} owns both `delayed()` forms: without a
 * `resumeAt` the session parks like `waiting()`; with one it parks on
 * `paused` and the engine-owned `system.delayed.{nodeId}.resume_at` marker
 * is written directly (never through handler `stateChanges`, so it never has
 * to be added to {@see SystemStateNamespacePolicy}'s allowlist).
 */
final class FlowSessionPersisterTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private FlowSessionPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->persister = new FlowSessionPersister(new SystemStateNamespacePolicy());
    }

    public function test_delayed_without_resume_at_parks_like_waiting(): void
    {
        $session = $this->makeSession();

        $this->persister->persist($session, NodeExecutionResult::delayed(), null, 'delayed_test', 'd1');

        $session->refresh();
        $this->assertSame(FlowSessionStatus::WaitingInput, $session->status);
        $this->assertNull(data_get($session->state, 'system.delayed.d1'));
    }

    public function test_delayed_with_resume_at_pauses_and_writes_the_marker(): void
    {
        $session  = $this->makeSession();
        $resumeAt = Carbon::now()->addMinute();

        $this->persister->persist(
            $session,
            NodeExecutionResult::delayed(resumeAt: $resumeAt),
            null,
            'delayed_test',
            'd1',
        );

        $session->refresh();
        $this->assertSame(FlowSessionStatus::Paused, $session->status);
        $this->assertSame(
            $resumeAt->format(DateTimeInterface::ATOM),
            data_get($session->state, 'system.delayed.d1.resume_at'),
        );
    }

    public function test_marker_is_cleared_once_the_node_later_completes(): void
    {
        $session = $this->makeSession();

        $this->persister->persist(
            $session,
            NodeExecutionResult::delayed(resumeAt: Carbon::now()->addMinute()),
            null,
            'delayed_test',
            'd1',
        );
        $session->refresh();
        $this->assertNotNull(data_get($session->state, 'system.delayed.d1.resume_at'));

        $this->persister->persist($session, NodeExecutionResult::executed(), 'n-end', 'delayed_test', 'd1');

        $session->refresh();
        $this->assertSame(FlowSessionStatus::Active, $session->status);
        $this->assertNull(data_get($session->state, 'system.delayed.d1'));
    }

    public function test_marker_is_cleared_when_the_node_ends_the_flow(): void
    {
        $session = $this->makeSession();

        $this->persister->persist(
            $session,
            NodeExecutionResult::delayed(resumeAt: Carbon::now()->addMinute()),
            null,
            'delayed_test',
            'd1',
        );
        $session->refresh();

        $this->persister->persistEnd($session, NodeExecutionResult::finished(), 'success', 'end', 'd1');

        $session->refresh();
        $this->assertSame(FlowSessionStatus::Ended, $session->status);
        $this->assertNull(data_get($session->state, 'system.delayed.d1'));
    }

    public function test_a_second_delayed_node_does_not_disturb_the_first_nodes_marker(): void
    {
        $session = $this->makeSession();

        $this->persister->persist(
            $session,
            NodeExecutionResult::delayed(resumeAt: Carbon::now()->addMinute()),
            null,
            'delayed_test',
            'd1',
        );
        $session->refresh();

        $this->persister->persist(
            $session,
            NodeExecutionResult::delayed(resumeAt: Carbon::now()->addMinutes(2)),
            null,
            'delayed_test',
            'd2',
        );

        $session->refresh();
        $this->assertNotNull(data_get($session->state, 'system.delayed.d1.resume_at'));
        $this->assertNotNull(data_get($session->state, 'system.delayed.d2.resume_at'));
    }

    private function makeSession(): FlowSession
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();

        $definition = FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Persister Test',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        return FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'd1',
            'state'              => [],
            'status'             => FlowSessionStatus::Active,
            'version'            => 1,
        ]);
    }
}
