<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Webhook;

use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Webhook\RedisWebhookReplyStore;
use App\Domains\Flow\Webhook\WebhookReply;
use App\Domains\Flow\Webhook\WebhookReplyStoreInterface;
use App\Jobs\Flow\StartAutomationRunJob;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * What a run a webhook started answers its waiting sender, end to end through the engine and a real Redis: the
 * `webhook_response` node's answer (from the run's state, also from a subflow, the first one winning), or the run's
 * end — completed, cancelled, failed — when no node answered; and nothing for a run no webhook started.
 */
#[Group('redis')]
final class WebhookRunReplyTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private string $triggerId;

    /** @var list<string> */
    private array $fireKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->app->make(NodeHandlerRegistry::class)->register(WebhookRunFailingTestHandler::class);
        $this->triggerId = mb_strtolower((string) Str::uuid());
    }

    protected function tearDown(): void
    {
        foreach ($this->fireKeys as $fireKey) {
            Redis::connection()->del(
                RedisWebhookReplyStore::key(self::TENANT_ID, $this->triggerId, $fireKey),
                RedisWebhookReplyStore::wakeKey(self::TENANT_ID, $this->triggerId, $fireKey),
            );
        }

        parent::tearDown();
    }

    public function test_the_node_answers_with_its_status_and_a_body_rendered_from_the_request(): void
    {
        $flowId = $this->flow([
            $this->respond(['status' => 201, 'body_type' => 'json', 'body' => ['hello' => '{{flow.webhook.body.name}}', 'n' => 1]]),
            $this->end('success'),
        ]);

        $reply = $this->startRun($flowId, 'wh:node');

        $this->assertNotNull($reply);
        $this->assertSame(201, $reply->status);
        $this->assertSame(WebhookReply::TYPE_JSON, $reply->type);
        // jsonb on PostgreSQL reorders a definition's keys, so the body is compared without its key order.
        $body = json_decode($reply->body, true, 4, JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        ksort($body);
        $this->assertSame(['hello' => 'Ann "the" <b>', 'n' => 1], $body);
    }

    public function test_a_text_answer_is_rendered_as_text(): void
    {
        $flowId = $this->flow([
            $this->respond(['status' => '202', 'body_type' => 'text', 'text' => 'Thanks, {{flow.webhook.body.name}}']),
            $this->end('success'),
        ]);

        $reply = $this->startRun($flowId, 'wh:text');

        $this->assertSame(202, $reply?->status);
        $this->assertSame(WebhookReply::TYPE_TEXT, $reply->type);
        $this->assertSame('Thanks, Ann "the" <b>', $reply->body);
    }

    public function test_the_first_answer_wins_over_a_second_node_and_the_end_of_the_run(): void
    {
        $flowId = $this->flow([
            $this->respond(['status' => 200, 'body_type' => 'text', 'text' => 'first'], 'r1'),
            $this->respond(['status' => 400, 'body_type' => 'text', 'text' => 'second'], 'r2'),
            $this->end('failed'),
        ]);

        $reply = $this->startRun($flowId, 'wh:first');

        $this->assertSame(200, $reply?->status);
        $this->assertSame('first', $reply->body);
    }

    public function test_without_a_node_the_end_of_the_run_answers(): void
    {
        $this->assertSame([200, '{"status":"completed"}'], $this->statusAndBody($this->startRun($this->flow([$this->end('success')]), 'wh:ok')));
        $this->assertSame([200, '{"status":"cancelled"}'], $this->statusAndBody($this->startRun($this->flow([$this->end('cancelled')]), 'wh:cancel')));
        $this->assertSame([500, '{"status":"failed"}'], $this->statusAndBody($this->startRun($this->flow([$this->end('failed')]), 'wh:fail')));

        // A run that just runs off its graph completes.
        $this->assertSame([200, '{"status":"completed"}'], $this->statusAndBody($this->startRun($this->flow([
            ['id' => 'noop', 'type' => 'webhook_response_failing_test', 'version' => 1, 'config' => ['action' => 'pass']],
        ]), 'wh:off-graph')));
    }

    public function test_a_run_that_fails_on_a_node_answers_failed(): void
    {
        $flowId = $this->flow([
            ['id' => 'boom', 'type' => 'webhook_response_failing_test', 'version' => 1, 'config' => ['action' => 'throw']],
            $this->end('success'),
        ]);

        try {
            $this->startRun($flowId, 'wh:boom');
            $this->fail('The node failure should surface to the job.');
        } catch (RuntimeException $exception) {
            $this->assertSame('node failed', $exception->getMessage());
        }

        $this->assertSame([500, '{"status":"failed"}'], $this->statusAndBody($this->reply('wh:boom')));
    }

    public function test_a_node_in_a_subflow_answers_for_the_run_and_the_child_end_does_not(): void
    {
        $childId = $this->flow([
            $this->respond(['status' => 207, 'body_type' => 'text', 'text' => 'from the child']),
            $this->end('failed'),
        ]);

        $parentId = $this->flow([
            ['id' => 'sub', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $childId, 'timeout' => 'PT1H']],
            $this->end('success'),
        ], [
            ['id' => 'e1', 'from' => 'sub', 'to' => 'end', 'handle' => 'failed'],
        ]);

        $reply = $this->startRun($parentId, 'wh:sub');

        $this->assertSame(207, $reply?->status);
        $this->assertSame('from the child', $reply->body);
    }

    public function test_a_run_no_webhook_started_answers_nobody(): void
    {
        $flowId = $this->flow([
            $this->respond(['status' => 200, 'body_type' => 'text', 'text' => 'nobody']),
            $this->end('success'),
        ]);

        $this->assertNull($this->startRun($flowId, 'slot-2026-11-20T10:00'));
    }

    /**
     * Start the run as a webhook's job would, and read what it answered.
     */
    private function startRun(string $flowId, string $fireKey): ?WebhookReply
    {
        $this->fireKeys[] = $fireKey;

        $this->app->call([new StartAutomationRunJob(
            tenantId: self::TENANT_ID,
            flowId: $flowId,
            triggerId: $this->triggerId,
            fireKey: $fireKey,
            initialState: ['flow' => ['webhook' => ['body' => ['name' => 'Ann "the" <b>']]]],
        ), 'handle']);

        return $this->reply($fireKey);
    }

    private function reply(string $fireKey): ?WebhookReply
    {
        return $this->app->make(WebhookReplyStoreInterface::class)->find(self::TENANT_ID, $this->triggerId, $fireKey);
    }

    /**
     * @return array{int, string}|null
     */
    private function statusAndBody(?WebhookReply $reply): ?array
    {
        return null === $reply ? null : [$reply->status, $reply->body];
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return array<string, mixed>
     */
    private function respond(array $config, string $id = 'respond'): array
    {
        return ['id' => $id, 'type' => 'webhook_response', 'version' => 1, 'config' => $config];
    }

    /**
     * @return array<string, mixed>
     */
    private function end(string $status): array
    {
        return ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => $status]];
    }

    /**
     * @param  list<array<string, mixed>>       $nodes
     * @param  list<array<string, mixed>>|null  $edges  null links the nodes in a row through `default`
     */
    private function flow(array $nodes, ?array $edges = null): string
    {
        if (null === $edges) {
            $edges = [];

            for ($i = 0; $i < count($nodes) - 1; ++$i) {
                $edges[] = ['id' => "e{$i}", 'from' => $nodes[$i]['id'], 'to' => $nodes[$i + 1]['id'], 'handle' => 'default'];
            }
        }

        $flowId = (string) Str::uuid();

        FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => $flowId,
            'version'   => 1,
            'name'      => 'Webhook run',
            'nodes'     => $nodes,
            'edges'     => $edges,
            'is_active' => true,
        ]);

        return $flowId;
    }
}

/**
 * A node that throws (`action: throw`) or just goes on.
 */
final class WebhookRunFailingTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'webhook_response_failing_test';
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
        return 'Failing probe';
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
        if ('throw' === ($nodeConfig['config']['action'] ?? null)) {
            throw new RuntimeException('node failed');
        }

        return NodeExecutionResult::executed();
    }
}
