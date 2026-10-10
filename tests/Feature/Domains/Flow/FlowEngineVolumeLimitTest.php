<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface as FlowMessageSenderInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\Quota\Exceptions\VolumeLimitReachedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * An exhausted outbound volume is final for the period, so no way into the engine may rethrow it:
 * a rethrow makes the queue retry the job and ask the operator again for nothing. Every entry point
 * stops the session as Failed and returns normally.
 */
final class FlowEngineVolumeLimitTest extends FeatureTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->app->make(NodeHandlerRegistry::class)->register(RefusedByVolumeLimitTestHandler::class);
    }

    public function test_start_stops_the_session_without_rethrowing(): void
    {
        $definition = $this->definition();
        $contact    = $this->contact();

        $session = $this->engine()->start($definition, $contact);

        $this->assertFailedWithLimitLog($session);
    }

    public function test_resume_stops_the_session_without_rethrowing(): void
    {
        $definition = $this->definition();
        $contact    = $this->contact();
        $session    = $this->seedSession($definition, $contact, 'n1', FlowSessionStatus::WaitingInput);

        $resumed = $this->engine()->resume($session, new IncomingMessage(
            updateId: 'upd-1',
            externalUserId: 'ext-user',
            externalChatId: 'ext-chat',
            text: 'hi',
            type: IncomingMessageType::Text,
            platform: 'telegram',
        ));

        $this->assertFailedWithLimitLog($resumed);
    }

    public function test_run_session_after_a_delay_stops_the_session_without_rethrowing(): void
    {
        $definition = $this->definition();
        $contact    = $this->contact();
        $session    = $this->seedSession($definition, $contact, 'n1', FlowSessionStatus::Active);

        $this->assertFailedWithLimitLog($this->engine()->runSession($session, resumedAfterDelay: true));
    }

    public function test_resume_from_node_for_an_event_stops_the_session_without_rethrowing(): void
    {
        $definition = $this->definition();
        $contact    = $this->contact();

        $session = $this->engine()->resumeFromNode($definition, $contact, 'n0', 'default');

        $this->assertFailedWithLimitLog($session);
    }

    public function test_resume_after_a_subflow_stops_the_parent_without_rethrowing(): void
    {
        $definition = $this->definition();
        $contact    = $this->contact();
        $parent     = $this->seedSession($definition, $contact, 'n0', FlowSessionStatus::PausedSubflow);

        $this->assertFailedWithLimitLog($this->engine()->resumeAfterSubflow($parent, 'default'));
    }

    public function test_an_input_prompt_refused_by_the_limit_fails_the_session_instead_of_retrying(): void
    {
        $this->app->instance(FlowMessageSenderInterface::class, new RefusingFlowMessageSender());
        $definition = $this->definitionWith([
            ['id' => 'n1', 'type' => 'input', 'version' => 1, 'config' => ['prompt' => ['en' => 'Your name?']]],
        ], []);
        $contact = $this->contact();

        $session = $this->engine()->start($definition, $contact);

        $this->assertSame(FlowSessionStatus::Failed, $session->status);
        $this->assertSame('input', DB::table('flow_logs')->where('session_id', $session->getKey())->value('node_type'));
    }

    public function test_a_send_message_node_without_an_error_exit_ends_the_session_by_its_error_handle(): void
    {
        $this->app->instance(FlowMessageSenderInterface::class, new RefusingFlowMessageSender());
        $definition = $this->definitionWith([
            ['id' => 'n1', 'type' => 'send_message', 'version' => 1, 'config' => ['content_type' => 'text', 'text' => ['en' => 'Hello']]],
        ], []);
        $contact = $this->contact();

        $session = $this->engine()->start($definition, $contact);

        $this->assertSame(FlowSessionStatus::Completed, $session->status);

        $log = DB::table('flow_logs')->where('session_id', $session->getKey())->sole();
        $this->assertSame('error', $log->source_handle);
    }

    public function test_a_refusal_in_a_subflow_child_hands_control_back_to_the_parents_failed_exit(): void
    {
        $contact = $this->contact();
        $parent  = $this->definitionWith(
            [
                ['id' => 'p-sub', 'type' => 'subflow', 'version' => 1, 'config' => []],
                ['id' => 'p-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'failed']],
            ],
            [['id' => 'pe1', 'from' => 'p-sub', 'to' => 'p-end', 'handle' => 'failed']],
        );
        $child = $this->definition();

        $parentSession = $this->seedSession($parent, $contact, 'p-sub', FlowSessionStatus::PausedSubflow);
        $childSession  = $this->seedSession($child, $contact, 'n1', FlowSessionStatus::Active);
        $childSession->forceFill(['parent_session_id' => (string) $parentSession->getKey()])->save();

        $result = $this->engine()->runSession($childSession->refresh());

        $this->assertSame(FlowSessionStatus::Failed, $result->status);

        $parentSession->refresh();
        $this->assertNotSame(FlowSessionStatus::PausedSubflow, $parentSession->status);
        $this->assertSame(
            'p-end',
            DB::table('flow_logs')->where('session_id', $parentSession->getKey())->value('node_id'),
            'The parent must run its failed exit.',
        );
    }

    private function assertFailedWithLimitLog(FlowSession $session): void
    {
        $this->assertSame(FlowSessionStatus::Failed, $session->status);

        $log = DB::table('flow_logs')->where('session_id', $session->getKey())->where('status', 'failed')->sole();
        $this->assertStringContainsString('reached: 10 of 10 this period', (string) $log->error);
    }

    private function engine(): FlowEngineInterface
    {
        return $this->app->make(FlowEngineInterface::class);
    }

    private function definition(): FlowDefinition
    {
        return $this->definitionWith(
            [
                ['id' => 'n0', 'type' => 'refused_by_volume_limit_test', 'version' => 1, 'config' => []],
                ['id' => 'n1', 'type' => 'refused_by_volume_limit_test', 'version' => 1, 'config' => []],
            ],
            [['id' => 'e1', 'from' => 'n0', 'to' => 'n1', 'handle' => 'default']],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    private function definitionWith(array $nodes, array $edges): FlowDefinition
    {
        return FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Limit path',
            'nodes'     => $nodes,
            'edges'     => $edges,
            'is_active' => true,
        ]);
    }

    private function contact(): Contact
    {
        $assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId]);
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        return Contact::factory()->forTenant($this->tenantId)->create();
    }

    private function seedSession(FlowDefinition $definition, Contact $contact, string $nodeId, FlowSessionStatus $status): FlowSession
    {
        return FlowSession::query()->create([
            'tenant_id'          => $this->tenantId,
            'contact_id'         => $contact->getKey(),
            'assistant_id'       => $this->app->make(CurrentAssistantInterface::class)->get()->getKey(),
            'flow_id'            => $definition->flow_id,
            'flow_definition_id' => (string) $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => $nodeId,
            'state'              => [],
            'status'             => $status,
            'version'            => 1,
        ]);
    }
}

final class RefusingFlowMessageSender implements FlowMessageSenderInterface
{
    public function send(string $tenantId, string $contactId, string $sessionId, array $payload): string
    {
        throw new VolumeLimitReachedException('outbound_messages', 10, 10);
    }
}

final class RefusedByVolumeLimitTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'refused_by_volume_limit_test';
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
        return 'Refused by volume limit';
    }

    public function category(): string
    {
        return 'Test';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        throw new VolumeLimitReachedException('outbound_messages', 10, 10);
    }
}
