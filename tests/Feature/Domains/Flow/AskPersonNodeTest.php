<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Contracts\FlowQuestionsInterface;
use App\Domains\Flow\DTOs\FlowRunOrigin;
use App\Domains\Flow\Enums\FlowQuestionPressOutcome;
use App\Domains\Flow\Enums\FlowQuestionStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Handlers\AskPersonNodeHandler;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowQuestion;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Support\FlowQuestionButton;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Jobs\Flow\ContinueAfterFlowQuestionJob;
use App\Jobs\Flow\ResumeDelayedFlowSessionJob;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * The `ask_person` node end to end: a run asks a contact a question with buttons, parks, and goes on by the exit of
 * the pressed button or by `timeout`; a press that may not answer decides nothing.
 */
final class AskPersonNodeTest extends FeatureTestCase
{
    private const string APPROVE = '7b0c8f8a-1d2e-4f3a-9b4c-5d6e7f8a9b01';
    private const string REJECT  = '7b0c8f8a-1d2e-4f3a-9b4c-5d6e7f8a9b02';

    private string $tenantId;

    private Assistant $assistant;

    private Contact $manager;

    /** @var list<string> */
    private array $guardedScopes = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 501]])]);

        // The tenant the test database seeds: the jobs switch to it by id.
        $this->tenantId = '00000000-0000-0000-0000-000000000001';
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: $this->tenantId, schemaName: 'main'));

        $this->assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId, 'default_language' => 'en']);
        $this->manager   = $this->contactOn($this->assistant, 'chat-manager');

        $scopes = &$this->guardedScopes;
        $this->app->instance(FlowExecutionGuardInterface::class, new class ($scopes) implements FlowExecutionGuardInterface {
            /** @param  list<string>  $scopes */
            public function __construct(private array &$scopes)
            {
            }

            public function run(string $tenantId, string $contactId, string $assistantId, Closure $callback): mixed
            {
                return $this->runInScope(new LockScope($tenantId, $contactId, $assistantId), $callback);
            }

            public function runInScope(LockScope $scope, Closure $callback): mixed
            {
                $this->scopes[] = $scope->triggerId ?? (string) $scope->contactId;

                return $callback();
            }
        });
    }

    public function test_a_contactless_run_asks_the_addressee_and_parks_until_the_question_expires(): void
    {
        $session  = $this->startRun();
        $question = $this->soleQuestion();

        $this->assertSame(FlowSessionStatus::Paused, $session->status);
        $this->assertSame('ask', $session->current_node_id);
        $this->assertSame($question->id, data_get($session->state, AskPersonNodeHandler::markerKey('ask')));
        $this->assertSame(FlowQuestionStatus::Pending, $question->status);
        $this->assertSame((string) $this->manager->getKey(), $question->contact_id);
        $this->assertSame((string) $this->assistant->getKey(), $question->assistant_id);
        $this->assertSame('501', $question->external_message_id);
        $this->assertSame('This has already been decided.', $question->late_answer_text);

        Http::assertSent(fn (Request $request): bool => 'chat-manager' === (string) ($request['chat_id'] ?? null)
            && 'Refund order 42?' === ($request['text'] ?? null)
            && str_contains((string) json_encode($request['reply_markup'] ?? null), 'q1' . $question->token . '01'));

        $wake = Queue::pushed(ResumeDelayedFlowSessionJob::class)->sole();
        $this->assertTrue($question->expires_at->equalTo(\Carbon\CarbonImmutable::parse($wake->resumeAt)));
    }

    public function test_a_press_of_the_addressee_continues_the_run_by_its_button_and_saves_the_answer(): void
    {
        $session  = $this->startRun();
        $question = $this->soleQuestion();

        $outcome = $this->press($question, 1);

        $this->assertSame(FlowQuestionPressOutcome::Accepted, $outcome);
        $this->assertSame(FlowQuestionStatus::Answered, $question->refresh()->status);
        $this->assertSame(self::REJECT, $question->answer_button_id);

        $this->runContinuation();

        $session->refresh();
        $this->assertSame(FlowSessionStatus::Ended, $session->status);
        $this->assertSame('failed', $session->end_status);
        $this->assertSame('reject', data_get($session->state, 'flow.decision'));
        $this->assertNull(data_get($session->state, AskPersonNodeHandler::markerKey('ask')));
        $this->assertSame([$this->triggerOf($session)], $this->guardedScopes);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'editMessageReplyMarkup'));
    }

    public function test_a_repeated_continuation_does_not_move_the_run_twice(): void
    {
        $session  = $this->startRun();
        $question = $this->soleQuestion();

        $this->press($question, 0);
        $this->runContinuation();
        $version = $session->refresh()->version;

        $this->app->make(\App\Domains\Flow\Orchestration\FlowQuestionResumer::class)->continue($question->id);

        $this->assertSame($version, $session->refresh()->version);
        $this->assertSame('success', $session->end_status);
    }

    public function test_a_press_that_is_not_the_addressees_decides_nothing_and_tells_nobody(): void
    {
        $session  = $this->startRun();
        $question = $this->soleQuestion();
        $stranger = $this->contactOn($this->assistant, 'chat-stranger');
        $other    = Assistant::factory()->create(['tenant_id' => $this->tenantId]);
        $sent     = $this->sentCount();

        $questions = $this->app->make(FlowQuestionsInterface::class);

        $this->assertSame(FlowQuestionPressOutcome::NotOurs, $questions->receivePress((string) $stranger->getKey(), (string) $this->assistant->getKey(), $question->token, 0, 'u1'));
        $this->assertSame(FlowQuestionPressOutcome::NotOurs, $questions->receivePress((string) $this->manager->getKey(), (string) $other->getKey(), $question->token, 0, 'u2'));
        $this->assertSame(FlowQuestionPressOutcome::NotOurs, $questions->receivePress((string) $this->manager->getKey(), (string) $this->assistant->getKey(), $question->token, 2, 'u3'));
        $this->assertSame(FlowQuestionPressOutcome::NotOurs, $questions->receivePress((string) $this->manager->getKey(), (string) $this->assistant->getKey(), str_repeat('0', 32), 0, 'u4'));

        $this->assertSame(FlowQuestionStatus::Pending, $question->refresh()->status);
        $this->assertSame(FlowSessionStatus::Paused, $session->refresh()->status);
        Queue::assertNotPushed(ContinueAfterFlowQuestionJob::class);
        $this->assertSame($sent, $this->sentCount());
    }

    public function test_the_run_takes_timeout_when_nobody_answers_and_a_late_press_only_gets_the_late_text(): void
    {
        $session  = $this->startRun();
        $question = $this->soleQuestion();

        $this->travel(3601)->seconds();
        $this->app->call([Queue::pushed(ResumeDelayedFlowSessionJob::class)->sole(), 'handle']);

        $session->refresh();
        $this->assertSame(FlowSessionStatus::Ended, $session->status);
        $this->assertSame('cancelled', $session->end_status);
        $this->assertSame(FlowQuestionStatus::Expired, $question->refresh()->status);
        $version = $session->version;

        $this->assertSame(FlowQuestionPressOutcome::Late, $this->press($question, 0));

        $this->assertSame($version, $session->refresh()->version);
        Queue::assertNotPushed(ContinueAfterFlowQuestionJob::class);
        Http::assertSent(static fn (Request $request): bool => 'This has already been decided.' === ($request['text'] ?? null));
    }

    public function test_an_answer_that_beats_the_timeout_wake_up_is_read_by_the_wake_up(): void
    {
        $session  = $this->startRun();
        $question = $this->soleQuestion();

        $this->press($question, 0);
        $this->travel(3601)->seconds();
        $this->app->call([Queue::pushed(ResumeDelayedFlowSessionJob::class)->sole(), 'handle']);

        $this->assertSame('success', $session->refresh()->end_status);
        $this->assertSame(FlowQuestionStatus::Answered, $question->refresh()->status);
        $this->assertFalse($this->app->make(FlowQuestionsInterface::class)->expire($question->id));
    }

    public function test_a_press_after_the_run_died_closes_the_question_and_gets_the_late_text(): void
    {
        $session  = $this->startRun();
        $question = $this->soleQuestion();

        FlowSession::query()->whereKey($session->getKey())->update(['status' => FlowSessionStatus::Failed->value]);

        $this->assertSame(FlowQuestionPressOutcome::Late, $this->press($question, 0));
        $this->assertSame(FlowQuestionStatus::Closed, $question->refresh()->status);
        Queue::assertNotPushed(ContinueAfterFlowQuestionJob::class);
        Http::assertSent(static fn (Request $request): bool => 'This has already been decided.' === ($request['text'] ?? null));
    }

    public function test_a_press_before_the_asking_step_committed_its_marker_is_still_accepted(): void
    {
        $session  = $this->startRun();
        $question = $this->soleQuestion();

        // The step sends before it persists `paused` and the marker: a fast press sees the session still active there.
        $state = $session->state;
        data_set($state, 'system.ask_person', []);
        FlowSession::query()->whereKey($session->getKey())->update(['status' => FlowSessionStatus::Active->value, 'state' => json_encode($state)]);

        $this->assertSame(FlowQuestionPressOutcome::Accepted, $this->press($question, 0));
        $this->assertSame(FlowQuestionStatus::Answered, $question->refresh()->status);
    }

    public function test_a_retried_step_finds_the_same_question_and_sends_it_once(): void
    {
        $session  = $this->startRun();
        $question = $this->soleQuestion();
        $sent     = $this->sentCount();

        $result = $this->askAgain($session, $question->execution_key);

        $this->assertSame($question->id, $result->stateChanges[AskPersonNodeHandler::markerKey('ask')]);
        $this->assertSame(1, FlowQuestion::query()->count());
        $this->assertSame($sent, $this->sentCount());
    }

    public function test_the_wait_ends_a_minute_before_the_runs_deadline(): void
    {
        $session = $this->startRun();
        $session->forceFill(['deadline_at' => now()->addHours(2)])->save();

        $result = $this->askAgain($session, 'key-2', 604_800);

        $question = FlowQuestion::query()->where('execution_key', 'key-2')->sole();
        $this->assertTrue($question->expires_at->equalTo($session->refresh()->deadline_at->subSeconds(60)));
        $this->assertTrue($result->metadata['clamped_by_run_deadline']);
    }

    public function test_with_less_than_a_minute_to_the_deadline_the_node_times_out_without_asking(): void
    {
        $session = $this->startRun();
        $session->forceFill(['deadline_at' => now()->addSeconds(30)])->save();

        $result = $this->askAgain($session, 'key-3');

        $this->assertSame('timeout', $result->sourceHandle);
        $this->assertTrue($result->metadata['clamped_by_run_deadline']);
        $this->assertSame(0, FlowQuestion::query()->where('execution_key', 'key-3')->count());
    }

    public function test_an_unresolved_addressee_takes_error_and_sends_nothing(): void
    {
        $session = $this->startRun(managerId: 'not-a-uuid');

        // The `error` exit has no edge here: the run stops there.
        $this->assertSame(FlowSessionStatus::Completed, $session->status);
        $completed = \App\Domains\Flow\Models\FlowSessionHistoryEntry::query()
            ->where('session_id', $session->getKey())
            ->where('event_type', 'node_completed')
            ->sole();
        $this->assertSame('error', $completed->metadata['handle']);
        $this->assertSame(0, FlowQuestion::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_question_goes_with_its_run_and_with_its_addressee(): void
    {
        $session = $this->startRun();
        $this->assertSame(1, FlowQuestion::query()->count());

        $session->delete();
        $this->assertSame(0, FlowQuestion::query()->count());

        $this->startRun();
        $this->assertSame(1, FlowQuestion::query()->count());

        $this->manager->delete();
        $this->assertSame(0, FlowQuestion::query()->count());
    }

    public function test_a_press_through_the_router_never_reaches_the_addressees_own_waiting_conversation(): void
    {
        $run      = $this->startRun();
        $question = $this->soleQuestion();
        $own      = FlowSession::query()->create([
            'tenant_id'          => $this->tenantId,
            'assistant_id'       => $this->assistant->getKey(),
            'contact_id'         => $this->manager->getKey(),
            'flow_definition_id' => $run->flow_definition_id,
            'flow_version'       => 1,
            'current_node_id'    => 'ask',
            'state'              => [],
            'status'             => FlowSessionStatus::WaitingInput,
            'version'            => 1,
        ]);
        $router  = $this->app->make(\App\Domains\Flow\Routing\MessageRouter::class);
        $channel = Channel::query()->where('assistant_id', $this->assistant->getKey())->sole();

        $unknown = $router->route($this->manager, $this->buttonPress(FlowQuestionButton::encode(str_repeat('0', 32), 0), 'u1'), $this->assistant, $channel);
        $known   = $router->route($this->manager, $this->buttonPress(FlowQuestionButton::encode($question->token, 0), 'u2'), $this->assistant, $channel);

        $this->assertSame('flow_question_answer', $unknown->reason);
        $this->assertSame('flow_question_answer', $known->reason);
        $this->assertSame(1, $own->refresh()->version);
        $this->assertSame(FlowSessionStatus::WaitingInput, $own->status);
        $this->assertSame(2, FlowSession::query()->count());
        $this->assertSame(FlowQuestionStatus::Answered, $question->refresh()->status);
        Queue::assertPushed(ContinueAfterFlowQuestionJob::class, 1);
    }

    public function test_an_authors_send_message_button_cannot_carry_a_question_payload(): void
    {
        $definition = FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Forged',
            'nodes'     => [['id' => 'say', 'type' => 'send_message', 'version' => 1, 'config' => [
                'content_type'  => 'text_with_keyboard',
                'keyboard_mode' => 'inline',
                'text'          => 'Pick',
                'buttons'       => [['id' => self::APPROVE, 'label' => 'Go', 'value' => 'go', 'callback_payload' => 'q1' . str_repeat('a', 34)]],
            ]]],
            'edges'     => [],
            'is_active' => true,
        ]);

        $this->app->make(\App\Domains\Assistant\Contracts\CurrentAssistantInterface::class)->set($this->assistant);
        $session = $this->app->make(FlowEngineInterface::class)->start($definition, $this->manager);

        $expected = \App\Domains\Flow\Support\CallbackDataCodec::encode((string) $session->getKey(), self::APPROVE);
        Http::assertSent(static fn (Request $request): bool => str_contains((string) json_encode($request['reply_markup'] ?? null), $expected));
        Http::assertNotSent(static fn (Request $request): bool => str_contains((string) json_encode($request['reply_markup'] ?? null), 'q1aaaa'));
    }

    private function buttonPress(string $payload, string $updateId): \Fapost\Foundation\DTO\IncomingMessage
    {
        return new \Fapost\Foundation\DTO\IncomingMessage(
            updateId: $updateId,
            externalUserId: 'chat-manager',
            externalChatId: 'chat-manager',
            text: $payload,
            type: \Fapost\Foundation\DTO\IncomingMessageType::CallbackQuery,
            platform: 'telegram',
        );
    }

    private function startRun(int $timeoutSeconds = 3600, ?string $managerId = null): FlowSession
    {
        $definition = FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Approval',
            'nodes'     => [
                $this->askNode($timeoutSeconds),
                ['id' => 'ok', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
                ['id' => 'no', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'failed']],
                ['id' => 'late', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'cancelled']],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'ask', 'to' => 'ok', 'handle' => self::APPROVE],
                ['id' => 'e2', 'from' => 'ask', 'to' => 'no', 'handle' => self::REJECT],
                ['id' => 'e3', 'from' => 'ask', 'to' => 'late', 'handle' => 'timeout'],
            ],
            'is_active' => true,
        ]);

        $engine = $this->app->make(FlowEngineInterface::class);

        return $engine->start(
            $definition,
            null,
            ['flow' => ['manager_id' => $managerId ?? (string) $this->manager->getKey(), 'order_id' => '42']],
            FlowRunOrigin::automation((string) Str::uuid(), (string) $this->assistant->getKey()),
        );
    }

    private function askAgain(FlowSession $session, string $executionKey, int $timeoutSeconds = 3600): \Fapost\Foundation\DTO\NodeExecutionResult
    {
        $context = new \Fapost\Foundation\DTO\NodeExecutionContext(
            tenantId: $this->tenantId,
            contactId: null,
            sessionId: (string) $session->getKey(),
            nodeId: 'ask',
            idempotencyKey: $executionKey,
            platform: null,
            resolvedLanguage: 'en',
        );

        return $this->app->make(AskPersonNodeHandler::class)
            ->execute($this->askNode($timeoutSeconds), ['flow' => ['manager_id' => (string) $this->manager->getKey()]], $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function askNode(int $timeoutSeconds = 3600): array
    {
        return ['id' => 'ask', 'type' => 'ask_person', 'version' => 1, 'config' => [
            'addressee' => ['kind' => 'contact', 'contact' => '{{flow.manager_id}}'],
            'text'      => 'Refund order {{flow.order_id}}?',
            'buttons'   => [
                ['id' => self::APPROVE, 'label' => 'Approve', 'value' => 'approve'],
                ['id' => self::REJECT, 'label' => 'Reject', 'value' => 'reject'],
            ],
            'timeout_seconds'  => $timeoutSeconds,
            'save_to_variable' => ['name' => 'decision', 'type' => 'text', 'storage' => 'session'],
        ]];
    }

    private function contactOn(Assistant $assistant, string $chatId): Contact
    {
        $contact = Contact::factory()->forTenant($this->tenantId)->create(['external_id' => $chatId]);
        $channel = Channel::query()->where('assistant_id', $assistant->getKey())->first()
            ?? Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
                'assistant_id' => $assistant->getKey(),
                'tenant_id'    => $this->tenantId,
                'type'         => ChannelTypeEnum::Telegram,
                'token'        => 'bot-token',
                'is_active'    => true,
            ]));

        ChannelContact::query()->create([
            'contact_id'          => $contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => now(),
        ]);

        return $contact;
    }

    private function soleQuestion(): FlowQuestion
    {
        return FlowQuestion::query()->sole();
    }

    private function press(FlowQuestion $question, int $index): FlowQuestionPressOutcome
    {
        return $this->app->make(FlowQuestionsInterface::class)->receivePress(
            (string) $this->manager->getKey(),
            (string) $this->assistant->getKey(),
            $question->token,
            $index,
            'press-' . $index,
        );
    }

    private function runContinuation(): void
    {
        $this->app->call([Queue::pushed(ContinueAfterFlowQuestionJob::class)->sole(), 'handle']);
    }

    private function triggerOf(FlowSession $session): string
    {
        return (string) $session->trigger_id;
    }

    private function sentCount(): int
    {
        return Http::recorded()->count();
    }
}
