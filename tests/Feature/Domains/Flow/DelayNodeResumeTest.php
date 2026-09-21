<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Jobs\Flow\ResumeDelayedFlowSessionJob;
use Closure;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * A `delay` node parks the session and schedules {@see ResumeDelayedFlowSessionJob};
 * the job wakes the session under the session lock once `resume_at` is due.
 */
final class DelayNodeResumeTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    /** @var list<array{string, string, string}> */
    private array $guardedScopes = [];

    private Contact $contact;

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $this->contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $this->app->make(CurrentAssistantInterface::class)->set($this->assistant);

        $scopes = &$this->guardedScopes;
        $this->app->instance(FlowExecutionGuardInterface::class, new class ($scopes) implements FlowExecutionGuardInterface {
            /** @param  list<array{string, string, string}>  $scopes */
            public function __construct(private array &$scopes)
            {
            }

            public function run(string $tenantId, string $contactId, string $assistantId, Closure $callback): mixed
            {
                $this->scopes[] = [$tenantId, $contactId, $assistantId];

                return $callback();
            }
        });
    }

    public function test_flow_proceeds_past_delay_once_the_resume_job_runs(): void
    {
        $session = $this->startDelayFlow();

        $this->assertSame(FlowSessionStatus::WaitingInput, $session->status);
        $this->assertSame('d1', $session->current_node_id);

        $job = $this->soleResumeJob();
        $this->assertSame('flow.execution', $job->queue);
        $this->assertSame((string) $session->getKey(), $job->sessionId);
        $this->assertSame('d1', $job->nodeId);
        $this->assertSame($session->state['system']['delay']['d1']['resume_at'], $job->resumeAt);

        $this->travel(61)->seconds();
        $this->app->call([$job, 'handle']);

        $session->refresh();
        $this->assertSame(FlowSessionStatus::Ended, $session->status);
        $this->assertSame(EndStatus::Success->value, $session->end_status);
        $this->assertSame(
            [[self::TENANT_ID, (string) $this->contact->getKey(), (string) $this->assistant->getKey()]],
            $this->guardedScopes,
        );
    }

    public function test_resume_job_released_early_reschedules_instead_of_advancing(): void
    {
        $session = $this->startDelayFlow();
        $job     = $this->soleResumeJob();

        $this->travel(30)->seconds();
        $this->app->call([$job, 'handle']);

        $session->refresh();
        $this->assertSame(FlowSessionStatus::WaitingInput, $session->status);
        $this->assertSame('d1', $session->current_node_id);
        Queue::assertPushed(
            ResumeDelayedFlowSessionJob::class,
            fn (ResumeDelayedFlowSessionJob $pushed): bool => $pushed !== $job && $pushed->resumeAt === $job->resumeAt,
        );
    }

    public function test_inbound_message_advances_only_after_delay_and_late_job_is_a_no_op(): void
    {
        $session = $this->startDelayFlow();
        $job     = $this->soleResumeJob();
        $engine  = $this->app->make(FlowEngineInterface::class);

        $this->travel(30)->seconds();
        $session = $engine->resume($session, $this->inbound('u1'));

        $this->assertSame(FlowSessionStatus::WaitingInput, $session->status);
        $this->assertSame('d1', $session->current_node_id);

        $this->travel(31)->seconds();
        $session = $engine->resume($session, $this->inbound('u2'));

        $this->assertSame(FlowSessionStatus::Ended, $session->status);

        $this->app->call([$job, 'handle']);

        $this->assertSame(FlowSessionStatus::Ended, $session->refresh()->status);
        Queue::assertPushed(ResumeDelayedFlowSessionJob::class, 1);
    }

    private function startDelayFlow(): FlowSession
    {
        $definition = FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Delay',
            'nodes'     => [
                ['id' => 'd1', 'type' => 'delay', 'version' => 1, 'config' => ['seconds' => 60]],
                ['id' => 'n-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges'     => [['id' => 'e1', 'from' => 'd1', 'to' => 'n-end', 'handle' => 'default']],
            'is_active' => true,
        ]);

        return $this->app->make(FlowEngineInterface::class)->start($definition, $this->contact);
    }

    private function soleResumeJob(): ResumeDelayedFlowSessionJob
    {
        Queue::assertPushed(ResumeDelayedFlowSessionJob::class, 1);

        return Queue::pushed(ResumeDelayedFlowSessionJob::class)->sole();
    }

    private function inbound(string $updateId): IncomingMessage
    {
        return new IncomingMessage(
            updateId: $updateId,
            externalUserId: 'ext',
            externalChatId: 'chat',
            text: 'hello',
            type: IncomingMessageType::Text,
            platform: 'telegram',
        );
    }
}
