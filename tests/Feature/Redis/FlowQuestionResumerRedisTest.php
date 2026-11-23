<?php

declare(strict_types=1);

namespace Tests\Feature\Redis;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Concurrency\SessionLockManager;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Enums\FlowQuestionStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Handlers\AskPersonNodeHandler;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowQuestion;
use App\Domains\Flow\Models\FlowSession;
use App\Jobs\Flow\ContinueAfterFlowQuestionJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\FeatureTestCase;

/**
 * {@see ContinueAfterFlowQuestionJob} against a real Redis lock: it runs the answered question's run under the run's
 * own lock, and a busy lock puts the job back without running anything.
 */
#[Group('redis')]
final class FlowQuestionResumerRedisTest extends FeatureTestCase
{
    use InteractsWithRedisLocks;

    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pingRedisOrFail();

        Queue::fake();

        config([
            'flow.lock.acquisition_retries' => 1,
            'flow.lock.retry_delay_ms'      => 50,
        ]);
    }

    public function test_the_answered_run_is_woken_under_its_own_lock(): void
    {
        [$session, $question, $scope] = $this->answeredQuestion();

        $engine = $this->createMock(FlowEngineInterface::class);
        $engine->expects($this->once())
            ->method('runSession')
            ->with($this->callback(static fn (FlowSession $arg): bool => $arg->getKey() === $session->getKey()), true)
            ->willReturn($session);
        $this->app->instance(FlowEngineInterface::class, $engine);

        $this->app->call([new ContinueAfterFlowQuestionJob(self::TENANT_ID, $question->id), 'handle']);

        $this->assertNull($this->redisGet($scope->key()), 'The lock must be gone once the run went on.');
    }

    public function test_a_busy_lock_releases_the_job_and_runs_nothing(): void
    {
        [, $question, $scope] = $this->answeredQuestion();

        $holder = $this->app->make(SessionLockManager::class)->acquire($scope, ttlSeconds: 30);
        $this->assertNotNull($holder);

        $engine = $this->createMock(FlowEngineInterface::class);
        $engine->expects($this->never())->method('runSession');
        $this->app->instance(FlowEngineInterface::class, $engine);

        $this->app->call([new ContinueAfterFlowQuestionJob(self::TENANT_ID, $question->id), 'handle']);

        $this->assertSame($holder->token, $this->redisGet($scope->key()));
    }

    /**
     * @return array{0: FlowSession, 1: FlowQuestion, 2: LockScope}
     */
    private function answeredQuestion(): array
    {
        $assistant  = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact    = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $definition = FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Question Redis Test',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        $session = FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'ask',
            'state'              => [],
            'status'             => FlowSessionStatus::Paused,
            'version'            => 1,
        ]);

        $question = FlowQuestion::query()->create([
            'tenant_id'        => self::TENANT_ID,
            'token'            => bin2hex(random_bytes(16)),
            'session_id'       => $session->getKey(),
            'node_id'          => 'ask',
            'execution_key'    => 'k1',
            'contact_id'       => $contact->getKey(),
            'assistant_id'     => $assistant->getKey(),
            'buttons'          => [['id' => (string) Str::uuid(), 'value' => 'yes']],
            'status'           => FlowQuestionStatus::Answered,
            'answer_button_id' => null,
            'answered_at'      => Carbon::now(),
            'expires_at'       => Carbon::now()->addHour(),
        ]);

        $state = [];
        data_set($state, AskPersonNodeHandler::markerKey('ask'), $question->id);
        $session->forceFill(['state' => $state])->save();

        $scope = LockScope::ofSession($session);
        $this->trackLockKey($scope->key());

        return [$session, $question, $scope];
    }
}
