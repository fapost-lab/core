<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\DTOs\FlowRunOrigin;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\State\Exceptions\ContactlessSessionException;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Feature\FeatureTestCase;

/**
 * A flow session without a contact or an assistant — the base of runs started by schedule, event, webhook and API.
 * No trigger starts one yet: these tests are its entry point, calling {@see FlowEngineInterface::start()} with no
 * contact.
 */
final class ContactlessFlowSessionTest extends FeatureTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(
            new RuntimeTenant(id: $this->tenantId, schemaName: 'main'),
        );

        $this->app->make(NodeHandlerRegistry::class)->register(ContactlessProbeTestHandler::class);
    }

    public function test_a_contactless_run_completes_without_a_contact_or_an_assistant(): void
    {
        $definition = $this->definition([
            ['id' => 'write', 'type' => 'contactless_probe_test', 'version' => 1, 'config' => ['action' => 'write_flow']],
            ['id' => 'read',  'type' => 'contactless_probe_test', 'version' => 1, 'config' => ['action' => 'read_flow']],
            ['id' => 'end',   'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [
            ['id' => 'e1', 'from' => 'write', 'to' => 'read', 'handle' => 'default'],
            ['id' => 'e2', 'from' => 'read',  'to' => 'end',  'handle' => 'default'],
        ]);
        $triggerId = (string) Str::uuid();

        $session = $this->engine()->start($definition, null, [], FlowRunOrigin::automation($triggerId));

        $this->assertSame(FlowSessionStatus::Ended, $session->status);
        $this->assertNull($session->contact_id);
        $this->assertNull($session->assistant_id);
        $this->assertSame($this->tenantId, $session->tenant_id);
        $this->assertSame(FlowRunKind::Automation, $session->run_kind);
        $this->assertSame($triggerId, $session->trigger_id);
        $this->assertSame('written', data_get($session->state, 'flow.probe'));
        $this->assertSame('written', data_get($session->state, 'flow.seen'));
        $this->assertArrayNotHasKey('contact_id', $session->state['system']);
        $this->assertSame('', ContactlessProbeTestHandler::$contactIds[0]);
    }

    public function test_a_contactless_run_takes_no_ambient_assistant_and_keeps_the_one_its_origin_names(): void
    {
        $ambient = Assistant::factory()->create(['tenant_id' => $this->tenantId]);
        $named   = Assistant::factory()->create(['tenant_id' => $this->tenantId]);
        $this->app->make(CurrentAssistantInterface::class)->set($ambient);
        $definition = $this->endOnly();

        $withoutOrigin = $this->engine()->start($definition, null);
        $withAssistant = $this->engine()->start($definition, null, [], FlowRunOrigin::automation(assistantId: (string) $named->getKey()));

        $this->assertNull($withoutOrigin->assistant_id);
        $this->assertSame(FlowRunKind::Automation, $withoutOrigin->run_kind);
        $this->assertSame((string) $named->getKey(), $withAssistant->assistant_id);
    }

    public function test_a_conversation_without_a_contact_is_refused_before_anything_is_written(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->engine()->start($this->endOnly(), null, [], FlowRunOrigin::conversation());
        } finally {
            $this->assertSame(0, FlowSession::query()->count());
        }
    }

    public function test_reading_a_contact_path_fails_the_contactless_session_with_a_typed_error(): void
    {
        $definition = $this->definition([
            ['id' => 'read', 'type' => 'contactless_probe_test', 'version' => 1, 'config' => ['action' => 'read_contact']],
        ], []);

        try {
            $this->engine()->start($definition, null);
            $this->fail('A contact read on a contactless session must throw.');
        } catch (ContactlessSessionException $exception) {
            $this->assertStringContainsString('contact.name', $exception->getMessage());
        }

        $this->assertSame(FlowSessionStatus::Failed, FlowSession::query()->sole()->status);
    }

    public function test_writing_a_contact_path_fails_the_contactless_session_with_a_typed_error(): void
    {
        $definition = $this->definition([
            ['id' => 'write', 'type' => 'contactless_probe_test', 'version' => 1, 'config' => ['action' => 'write_contact']],
        ], []);

        try {
            $this->engine()->start($definition, null);
            $this->fail('A contact write on a contactless session must throw.');
        } catch (ContactlessSessionException $exception) {
            $this->assertStringContainsString('contact.city', $exception->getMessage());
        }

        $this->assertSame(FlowSessionStatus::Failed, FlowSession::query()->sole()->status);
    }

    public function test_a_subflow_child_inherits_the_run_kind_and_the_trigger(): void
    {
        $childFlowId = (string) Str::uuid();
        $this->definition([
            ['id' => 'c-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [], $childFlowId);
        $parent = $this->definition([
            ['id' => 'p-sub', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $childFlowId, 'timeout' => 'PT1H']],
            ['id' => 'p-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [
            ['id' => 'p-e1', 'from' => 'p-sub', 'to' => 'p-end', 'handle' => 'success'],
        ]);
        $triggerId = (string) Str::uuid();

        $session = $this->engine()->start($parent, null, [], FlowRunOrigin::automation($triggerId));

        $child = FlowSession::query()->where('parent_session_id', $session->getKey())->sole();
        $this->assertSame(FlowSessionStatus::Ended, $session->refresh()->status);
        $this->assertNull($child->contact_id);
        $this->assertSame(FlowRunKind::Automation, $child->run_kind);
        $this->assertSame($triggerId, $child->trigger_id);
    }

    public function test_a_conversation_start_still_records_a_conversation_with_its_assistant(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId]);
        $contact   = Contact::factory()->forTenant($this->tenantId)->create();
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $session = $this->engine()->start($this->endOnly(), $contact);

        $this->assertSame(FlowRunKind::Conversation, $session->run_kind);
        $this->assertNull($session->trigger_id);
        $this->assertSame((string) $contact->getKey(), $session->contact_id);
        $this->assertSame((string) $assistant->getKey(), $session->assistant_id);
        $this->assertSame((string) $contact->getKey(), data_get($session->state, 'system.contact_id'));
    }

    public function test_a_session_outlives_its_deleted_assistant(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId]);

        $session = $this->engine()->start(
            $this->endOnly(),
            null,
            [],
            FlowRunOrigin::automation(assistantId: (string) $assistant->getKey()),
        );

        $assistant->delete();

        $this->assertNull($session->refresh()->assistant_id);
    }

    protected function tearDown(): void
    {
        ContactlessProbeTestHandler::$contactIds = [];

        parent::tearDown();
    }

    private function engine(): FlowEngineInterface
    {
        return $this->app->make(FlowEngineInterface::class);
    }

    private function endOnly(): FlowDefinition
    {
        return $this->definition([
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], []);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    private function definition(array $nodes, array $edges, ?string $flowId = null): FlowDefinition
    {
        return FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => $flowId ?? (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Contactless',
            'nodes'     => $nodes,
            'edges'     => $edges,
            'is_active' => true,
        ]);
    }
}

/**
 * Exercises the state surface a contactless run gives a handler: session state works, the contact does not.
 */
final class ContactlessProbeTestHandler implements NodeHandlerInterface
{
    /** @var list<string> */
    public static array $contactIds = [];

    public function type(): string
    {
        return 'contactless_probe_test';
    }

    public function version(): int
    {
        return 1;
    }

    /**
     * @return list<int>
     */
    public function supportedVersions(): array
    {
        return [1];
    }

    public function label(): string
    {
        return 'Contactless probe';
    }

    public function category(): string
    {
        return 'Logic';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        self::$contactIds[] = $context->contactId;

        return match ($nodeConfig['config']['action'] ?? null) {
            'write_flow'    => NodeExecutionResult::executed(stateChanges: ['flow.probe' => 'written']),
            'read_flow'     => NodeExecutionResult::executed(stateChanges: ['flow.seen' => $context->stateReader?->read('flow.probe')]),
            'read_contact'  => NodeExecutionResult::executed(stateChanges: ['flow.name' => $context->stateReader?->read('contact.name')]),
            'write_contact' => $this->writeContact($context),
            default         => NodeExecutionResult::failed('unknown probe action'),
        };
    }

    private function writeContact(NodeExecutionContext $context): NodeExecutionResult
    {
        $context->contactWriter?->write('contact.city', 'Kyiv');

        return NodeExecutionResult::executed();
    }
}
