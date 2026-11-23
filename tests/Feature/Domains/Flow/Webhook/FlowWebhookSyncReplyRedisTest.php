<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Webhook;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowWebhookEndpoint;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Flow\Webhook\FlowWebhookEndpointService;
use App\Domains\Flow\Webhook\RedisWebhookReplyStore;
use App\Domains\Flow\Webhook\WebhookDeliveryDedupInterface;
use App\Domains\Flow\Webhook\WebhookReplyStoreInterface;
use App\Domains\Flow\Webhook\WebhookWaitSlotsInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\FeatureTestCase;
use Tests\Support\Flow\InMemoryWebhookDeliveryDedup;
use Tests\Support\Flow\InMemoryWebhookWaitSlots;

/**
 * Both halves of a sync webhook answer joined over a real Redis: the request waits under the key the receiver builds
 * (the tenant context and the trigger), the run — queued on the sync queue, so it runs inside the request — answers
 * under the key the engine builds (the run's tenant and trigger), and the sender gets that answer instead of 202.
 */
#[Group('redis')]
final class FlowWebhookSyncReplyRedisTest extends FeatureTestCase
{
    private const string SECRET = 'a-shared-secret-of-enough-length';

    /** The installation's tenant, which the run's job finds and switches to. */
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private Assistant $assistant;

    private string $triggerId = '';

    /** @var list<string> */
    private array $fireKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: self::TENANT_ID, schemaName: 'main'));

        $this->assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID, 'default_language' => 'en']);
        $this->app->make(CurrentAssistantInterface::class)->set($this->assistant);

        // The delivery claim and the wait slot are not part of the answer's key; only the reply store is the real one.
        $this->app->instance(WebhookDeliveryDedupInterface::class, new InMemoryWebhookDeliveryDedup());
        $this->app->instance(WebhookWaitSlotsInterface::class, new InMemoryWebhookWaitSlots());

        // A broken join fails in seconds rather than after the full wait.
        config(['flow.webhook.sync_wait_seconds' => 2]);
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

    public function test_a_sync_request_gets_the_answer_of_the_flows_response_node(): void
    {
        $this->assertInstanceOf(RedisWebhookReplyStore::class, $this->app->make(WebhookReplyStoreInterface::class));

        $token = $this->liveWebhook([
            ['id' => 'respond', 'type' => 'webhook_response', 'version' => 1, 'config' => [
                'status'    => 201,
                'body_type' => 'json',
                'body'      => ['order' => '{{flow.webhook.body.order}}'],
            ]],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ]);

        $response = $this->deliver($token, '{"order":"A-1"}', 'node-1')
            ->assertStatus(201)
            ->assertHeader('Content-Type', 'application/json');

        $this->assertSame('{"order":"A-1"}', $response->getContent());
    }

    public function test_a_sync_request_to_a_flow_without_a_response_node_gets_the_end_of_the_run(): void
    {
        $token = $this->liveWebhook([
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ]);

        $this->deliver($token, '{}', 'end-1')
            ->assertOk()
            ->assertExactJson(['status' => 'completed']);
    }

    /**
     * A published flow with one sync webhook trigger (GitHub-like: HMAC-SHA256 with a timestamp) and a secret.
     *
     * @param  list<array<string, mixed>>  $nodes  linked in a row through `default`
     *
     * @return string the token
     */
    private function liveWebhook(array $nodes): string
    {
        $edges = [];

        for ($i = 0; $i < count($nodes) - 1; ++$i) {
            $edges[] = ['id' => "e{$i}", 'from' => $nodes[$i]['id'], 'to' => $nodes[$i + 1]['id'], 'handle' => 'default'];
        }

        $this->triggerId = mb_strtolower(Str::ulid()->toRfc4122());
        $draft           = FlowDraft::factory()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => $nodes,
            'edges'        => $edges,
            'triggers'     => [[
                'id'        => $this->triggerId,
                'type'      => 'webhook',
                'is_active' => true,
                'priority'  => 100,
                'config'    => [
                    'verification'     => 'hmac_sha256',
                    'signature_header' => 'X-Hub-Signature-256',
                    'signature_prefix' => 'sha256=',
                    'timestamp_header' => 'X-Timestamp',
                    'signed_payload'   => '{header.X-Timestamp}.{raw}',
                    'delivery_id'      => '{header.X-GitHub-Delivery}',
                    'response_mode'    => 'sync',
                ],
            ]],
        ]);

        try {
            $this->app->make(PublishFlowService::class)->execute($draft->flow_id);
        } catch (FlowValidationException $exception) {
            $this->fail('Publish refused: ' . json_encode(array_map(static fn ($error): string => "{$error->code} {$error->path}: {$error->message}", $exception->errors)));
        }

        $this->app->make(FlowWebhookEndpointService::class)->setSecret($draft, $this->triggerId, self::SECRET);

        return FlowWebhookEndpoint::query()->where('trigger_id', $this->triggerId)->value('token');
    }

    private function deliver(string $token, string $body, string $deliveryId): TestResponse
    {
        $this->fireKeys[] = 'wh:' . $deliveryId;
        $timestamp        = (string) time();

        return $this->call('POST', '/hooks/flow/' . $token, [], [], [], [
            'CONTENT_TYPE'             => 'application/json',
            'HTTP_X_GITHUB_DELIVERY'   => $deliveryId,
            'HTTP_X_TIMESTAMP'         => $timestamp,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET),
        ], $body);
    }
}
