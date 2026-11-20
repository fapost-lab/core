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
use App\Domains\Flow\Webhook\FlowWebhookReceiver;
use App\Domains\Flow\Webhook\WebhookDeliveryDedupInterface;
use App\Domains\Flow\Webhook\WebhookReply;
use App\Domains\Flow\Webhook\WebhookReplyStoreInterface;
use App\Domains\Flow\Webhook\WebhookWaitSlotsInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Services\CurrentAccessState;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Jobs\Flow\StartAutomationRunJob;
use Fapost\Foundation\Tenancy\DTO\TenantAccessState;
use Fapost\Foundation\Tenancy\Enums\AccessMode;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * `POST /hooks/flow/{token}`: what a webhook trigger accepts, what it refuses and how, and that only an accepted
 * request queues a run — once per delivery id.
 */
final class FlowWebhookTriggerTest extends FeatureTestCase
{
    private const string SECRET = 'a-shared-secret-of-enough-length';

    private string $tenantId;

    private Assistant $assistant;

    private InMemoryWebhookDeliveryDedup $dedup;

    private InMemoryWebhookReplyStore $replies;

    private InMemoryWebhookWaitSlots $slots;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: $this->tenantId, schemaName: 'main'));

        $this->assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId, 'default_language' => 'en']);
        $this->app->make(CurrentAssistantInterface::class)->set($this->assistant);

        $this->dedup = new InMemoryWebhookDeliveryDedup();
        $this->app->instance(WebhookDeliveryDedupInterface::class, $this->dedup);

        $this->replies = new InMemoryWebhookReplyStore();
        $this->app->instance(WebhookReplyStoreInterface::class, $this->replies);

        $this->slots = new InMemoryWebhookWaitSlots();
        $this->app->instance(WebhookWaitSlotsInterface::class, $this->slots);

        Queue::fake();
    }

    public function test_a_signed_request_queues_one_run_with_the_delivery_id_as_fire_key_and_answers_202(): void
    {
        [$draft, $triggerId, $token] = $this->liveWebhook();
        $body                        = json_encode(['order' => ['id' => 42], 'note' => 'hi'], JSON_THROW_ON_ERROR);

        $this->deliver($token, $body, [
            'X-GitHub-Delivery' => 'delivery-1',
            'Authorization'     => 'Bearer leaked',
            'Cookie'            => 'session=leaked',
            'X-Hub-Signature'   => 'sha1=leaked',
            'X-Custom'          => 'kept',
        ], query: '?source=crm')
            ->assertStatus(202)
            ->assertExactJson(['status' => 'accepted', 'delivery_id' => 'delivery-1']);

        Queue::assertPushed(StartAutomationRunJob::class, 1);
        Queue::assertPushed(StartAutomationRunJob::class, function (StartAutomationRunJob $job) use ($draft, $triggerId): bool {
            $webhook = $job->initialState['flow']['webhook'];

            $this->assertSame($this->tenantId, $job->tenantId);
            $this->assertSame($draft->flow_id, $job->flowId);
            $this->assertSame($triggerId, mb_strtolower($job->triggerId));
            $this->assertSame('wh:delivery-1', $job->fireKey);
            $this->assertSame((string) $this->assistant->getKey(), $job->assistantId);
            $this->assertSame('POST', $webhook['method']);
            $this->assertSame(['source' => 'crm'], $webhook['query']);
            $this->assertSame(['order' => ['id' => 42], 'note' => 'hi'], $webhook['body']);
            $this->assertSame('delivery-1', $webhook['delivery_id']);
            $this->assertNotEmpty($webhook['received_at']);
            $this->assertSame('kept', $webhook['headers']['x-custom']);

            foreach (['authorization', 'cookie', 'x-hub-signature-256', 'x-hub-signature', 'x-timestamp'] as $stripped) {
                $this->assertArrayNotHasKey($stripped, $webhook['headers']);
            }

            return true;
        });
    }

    public function test_a_wrong_or_missing_signature_or_a_stale_timestamp_is_refused_with_401_and_queues_nothing(): void
    {
        [, , $token] = $this->liveWebhook();
        $body        = '{"a":1}';

        $this->postRaw($token, $body, ['X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, 'wrong'), 'X-Timestamp' => (string) time()])
            ->assertStatus(401);
        $this->postRaw($token, $body, ['X-Timestamp' => (string) time()])->assertStatus(401);
        $this->deliver($token, $body, ['X-Timestamp' => (string) (time() - 3600)])->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_a_captured_request_replayed_with_a_fresh_timestamp_is_refused(): void
    {
        [, , $token] = $this->liveWebhook();
        $old         = (string) (time() - 3600);
        $signature   = 'sha256=' . hash_hmac('sha256', $old . '.{"a":1}', self::SECRET);

        // The timestamp is part of what is signed, so swapping in a fresh one breaks the signature.
        $this->postRaw($token, '{"a":1}', ['X-Hub-Signature-256' => $signature, 'X-Timestamp' => (string) time(), 'X-GitHub-Delivery' => 'new-id'])
            ->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_a_flow_whose_draft_or_assistant_is_gone_is_not_found(): void
    {
        [$deleted, , $orphanToken] = $this->liveWebhook();
        [, , $otherToken]          = $this->liveWebhook();

        // Rows left behind by a delete that bypassed the flow service: nobody could revoke the URL any more.
        FlowDraft::query()->where('flow_id', $deleted->flow_id)->delete();
        $this->deliver($orphanToken, '{}', ['X-GitHub-Delivery' => 'a'])->assertNotFound();

        $this->assistant->delete();
        $this->deliver($otherToken, '{}', ['X-GitHub-Delivery' => 'b'])->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_a_secret_in_the_query_string_is_never_accepted(): void
    {
        [, , $token] = $this->liveWebhook(config: ['verification' => 'header_equals', 'signature_header' => 'X-Webhook-Secret', 'response_mode' => 'async']);

        $this->postRaw($token, '{}', [], query: '?X-Webhook-Secret=' . self::SECRET)->assertStatus(401);
        $this->postRaw($token, '{}', ['X-Webhook-Secret' => self::SECRET])->assertStatus(202);

        Queue::assertPushed(StartAutomationRunJob::class, 1);
    }

    public function test_every_way_of_leading_nowhere_is_the_same_bare_404(): void
    {
        [, , $live] = $this->liveWebhook();

        [$inactiveDraft, $inactiveTrigger, $inactive] = $this->liveWebhook(active: false);
        [$unpublishedDraft, $draftOnlyTrigger]        = $this->webhookDraft();
        $draftOnly                                    = $this->secretEndpoint($unpublishedDraft, $draftOnlyTrigger);

        $unknown = str_repeat('A', 43);

        foreach ([$unknown, $inactive, $draftOnly] as $token) {
            $this->deliver($token, '{}', ['X-GitHub-Delivery' => 'd-' . $token])
                ->assertNotFound()
                ->assertExactJson(['status' => 'rejected']);
        }

        // A token of the wrong shape never reaches the controller.
        $this->postRaw('short', '{}', [])->assertNotFound();

        Queue::assertNothingPushed();
        $this->deliver($live, '{}', ['X-GitHub-Delivery' => 'still-works'])->assertStatus(202);
    }

    public function test_a_flow_with_no_published_version_is_not_found(): void
    {
        [$draft, , $token] = $this->liveWebhook();

        // The trigger row stays, but the flow has no active definition any more.
        \App\Domains\Flow\Models\FlowDefinition::query()->where('flow_id', $draft->flow_id)->update(['is_active' => false]);

        $this->deliver($token, '{}', ['X-GitHub-Delivery' => 'x'])->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_an_endpoint_without_a_secret_refuses_every_request(): void
    {
        [$draft, $triggerId] = $this->webhookDraft();
        $this->publish($draft);
        $endpoint = $this->app->make(FlowWebhookEndpointService::class)->ensure($draft, $triggerId);

        $this->deliver($endpoint->token, '{}', ['X-GitHub-Delivery' => 'x'])->assertStatus(401);
        Queue::assertNothingPushed();
    }

    public function test_a_repeated_delivery_is_answered_as_a_duplicate_and_queues_no_second_run(): void
    {
        [, , $token] = $this->liveWebhook();

        $this->deliver($token, '{"n":1}', ['X-GitHub-Delivery' => 'same'])->assertStatus(202);
        $this->deliver($token, '{"n":1}', ['X-GitHub-Delivery' => 'same'])
            ->assertOk()
            ->assertExactJson(['status' => 'duplicate', 'delivery_id' => 'same']);
        $this->deliver($token, '{"n":2}', ['X-GitHub-Delivery' => 'other'])->assertStatus(202);

        Queue::assertPushed(StartAutomationRunJob::class, 2);
    }

    public function test_a_request_without_a_delivery_id_gets_a_fresh_one_every_time(): void
    {
        [, , $token] = $this->liveWebhook();

        $first  = $this->deliver($token, '{}', [])->assertStatus(202)->json('delivery_id');
        $second = $this->deliver($token, '{}', [])->assertStatus(202)->json('delivery_id');

        $this->assertNotSame($first, $second);
        Queue::assertPushed(StartAutomationRunJob::class, 2);
        $this->assertSame([], $this->dedup->claimed, 'A fresh id is never claimed: there is nothing to deduplicate.');
    }

    public function test_a_long_delivery_id_becomes_a_hashed_fire_key(): void
    {
        [, , $token] = $this->liveWebhook();
        $long        = str_repeat('x', 300);

        $this->deliver($token, '{}', ['X-GitHub-Delivery' => $long])->assertStatus(202);

        Queue::assertPushed(StartAutomationRunJob::class, fn (StartAutomationRunJob $job): bool => $job->fireKey === 'wh:sha256:' . hash('sha256', $long));
    }

    public function test_a_form_post_hands_its_fields_to_the_flow(): void
    {
        [, , $token] = $this->liveWebhook();

        $body = 'name=Ann&age=30';

        // PHP fills the form fields of a real request; the test hands them over as the parsed parameters.
        $this->call('POST', '/hooks/flow/' . $token, ['name' => 'Ann', 'age' => '30'], [], [], [
            'CONTENT_TYPE'             => 'application/x-www-form-urlencoded',
            'HTTP_X_GITHUB_DELIVERY'   => 'f-1',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', ($now = (string) time()) . '.' . $body, self::SECRET),
            'HTTP_X_TIMESTAMP'         => $now,
        ], $body)->assertStatus(202);

        Queue::assertPushed(StartAutomationRunJob::class, fn (StartAutomationRunJob $job): bool => ['name' => 'Ann', 'age' => '30'] === $job->initialState['flow']['webhook']['body']);
    }

    public function test_a_body_over_the_limit_is_413_and_another_method_is_405(): void
    {
        [, , $token] = $this->liveWebhook();

        $this->deliver($token, str_repeat('a', FlowWebhookReceiver::MAX_BODY_BYTES + 1), ['X-GitHub-Delivery' => 'big'])->assertStatus(413);
        $this->get('/hooks/flow/' . $token)->assertStatus(405);
        $this->put('/hooks/flow/' . $token)->assertStatus(405);

        Queue::assertNothingPushed();
    }

    public function test_a_multipart_post_is_refused_415_before_anything_is_checked(): void
    {
        [, , $token] = $this->liveWebhook();

        // PHP keeps a multipart body from php://input: the flow would get no body and the size check nothing to measure.
        $this->deliver($token, '', ['X-GitHub-Delivery' => 'multi', 'Content-Type' => 'multipart/form-data; boundary=x'])->assertStatus(415);
        $this->deliver(str_repeat('D', 43), '', ['Content-Type' => 'Multipart/Form-Data; boundary=x'])->assertStatus(415);

        Queue::assertNothingPushed();
    }

    public function test_a_token_over_its_limit_is_answered_429_with_retry_after(): void
    {
        config(['flow.webhook.per_token_per_minute' => 2]);
        [, , $token] = $this->liveWebhook();

        $this->deliver($token, '{}', ['X-GitHub-Delivery' => '1'])->assertStatus(202);
        $this->deliver($token, '{}', ['X-GitHub-Delivery' => '2'])->assertStatus(202);
        $this->deliver($token, '{}', ['X-GitHub-Delivery' => '3'])->assertStatus(429)->assertHeader('Retry-After');

        Queue::assertPushed(StartAutomationRunJob::class, 2);
    }

    public function test_an_address_guessing_tokens_is_held_back_while_known_tokens_are_not_counted(): void
    {
        [, , $token] = $this->liveWebhook();

        for ($i = 0; $i < FlowWebhookReceiver::UNKNOWN_TOKENS_PER_MINUTE + 5; ++$i) {
            $this->deliver($token, '{}', ['X-GitHub-Delivery' => "known-{$i}"]);
        }

        $this->assertSame(0, $this->app->make(\Illuminate\Cache\RateLimiter::class)->attempts('flow-webhook:miss:' . $this->tenantId . ':127.0.0.1'));

        for ($i = 0; $i < FlowWebhookReceiver::UNKNOWN_TOKENS_PER_MINUTE; ++$i) {
            $this->deliver(mb_str_pad((string) $i, 43, 'B'), '{}', [])->assertNotFound();
        }

        $this->deliver(str_repeat('C', 43), '{}', [])->assertStatus(429);
        // Even a real token waits: the address is the one held back, before any lookup.
        $this->deliver($token, '{}', ['X-GitHub-Delivery' => 'after'])->assertStatus(429);
    }

    public function test_a_stopped_tenant_answers_503_with_retry_after(): void
    {
        [, , $token] = $this->liveWebhook();
        $this->app->make(CurrentAccessState::class)->set(new TenantAccessState(AccessMode::Stopped));

        $this->deliver($token, '{}', ['X-GitHub-Delivery' => 's'])
            ->assertStatus(503)
            ->assertHeader('Retry-After', (string) FlowWebhookReceiver::STOPPED_RETRY_AFTER_SECONDS);

        Queue::assertNothingPushed();
    }

    public function test_a_failed_dispatch_gives_the_claim_back(): void
    {
        [, , $token] = $this->liveWebhook();
        $this->mock(\Illuminate\Contracts\Bus\Dispatcher::class)
            ->shouldReceive('dispatch')
            ->andThrow(new RuntimeException('queue down'));

        $this->withoutExceptionHandling();

        try {
            $this->deliver($token, '{}', ['X-GitHub-Delivery' => 'lost']);
            $this->fail('The dispatch failure should surface.');
        } catch (RuntimeException $exception) {
            $this->assertSame('queue down', $exception->getMessage());
        }

        $this->assertSame([], $this->dedup->claimed);
    }

    public function test_a_sync_trigger_answers_with_the_flows_reply_while_holding_a_wait_slot(): void
    {
        [, $triggerId, $token]                = $this->liveWebhook(config: $this->syncConfig());
        $this->replies->prepared['wh:sync-1'] = new WebhookReply(201, '{"order":"created"}', WebhookReply::TYPE_JSON);

        $response = $this->deliver($token, '{}', ['X-GitHub-Delivery' => 'sync-1'])
            ->assertStatus(201)
            ->assertHeader('Content-Type', 'application/json');

        $this->assertSame('{"order":"created"}', $response->getContent());
        Queue::assertPushed(StartAutomationRunJob::class, 1);
        $this->assertSame([[$this->tenantId, $triggerId, 'wh:sync-1', 30]], $this->replies->awaited);
        $this->assertSame([[5, 2, 35]], $this->slots->acquired, 'The installation and tenant ceilings, and the wait plus its grace.');
        $this->assertSame([], $this->slots->held, 'The slot is given back.');
    }

    public function test_a_text_reply_is_sent_as_plain_text_and_carries_no_header_of_the_flow(): void
    {
        [, , $token]                             = $this->liveWebhook(config: $this->syncConfig());
        $this->replies->prepared['wh:sync-text'] = new WebhookReply(200, 'ok', WebhookReply::TYPE_TEXT);

        $response = $this->deliver($token, '{}', ['X-GitHub-Delivery' => 'sync-text'])->assertOk();

        $this->assertSame('ok', $response->getContent());
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_a_sync_request_without_a_free_slot_gets_202_at_once_and_does_not_wait(): void
    {
        [, , $token]                        = $this->liveWebhook(config: $this->syncConfig());
        $this->slots->full                  = true;
        $this->replies->prepared['wh:busy'] = new WebhookReply(200, '{}', WebhookReply::TYPE_JSON);

        $this->deliver($token, '{}', ['X-GitHub-Delivery' => 'busy'])
            ->assertStatus(202)
            ->assertExactJson(['status' => 'accepted', 'delivery_id' => 'busy']);

        $this->assertSame([], $this->replies->awaited);
        Queue::assertPushed(StartAutomationRunJob::class, 1);
    }

    public function test_a_sync_request_whose_flow_does_not_answer_in_time_gets_202_and_gives_its_slot_back(): void
    {
        [, , $token] = $this->liveWebhook(config: $this->syncConfig());
        config(['flow.webhook.sync_wait_seconds' => 3]);

        $this->deliver($token, '{}', ['X-GitHub-Delivery' => 'slow'])
            ->assertStatus(202)
            ->assertExactJson(['status' => 'accepted', 'delivery_id' => 'slow']);

        $this->assertSame(3, $this->replies->awaited[0][3]);
        $this->assertSame([], $this->slots->held);
    }

    public function test_a_repeated_sync_delivery_gets_the_kept_answer_or_duplicate_and_never_waits(): void
    {
        [, , $token] = $this->liveWebhook(config: $this->syncConfig());

        $this->deliver($token, '{}', ['X-GitHub-Delivery' => 'again'])->assertStatus(202);
        $this->assertCount(1, $this->replies->awaited);

        // No answer kept (yet): the plain duplicate answer.
        $this->deliver($token, '{}', ['X-GitHub-Delivery' => 'again'])
            ->assertOk()
            ->assertExactJson(['status' => 'duplicate', 'delivery_id' => 'again']);

        // The flow answered meanwhile: a repeat gets that answer while it is kept.
        $this->replies->prepared['wh:again'] = new WebhookReply(202, '{"queued":true}', WebhookReply::TYPE_JSON);
        $this->assertSame('{"queued":true}', $this->deliver($token, '{}', ['X-GitHub-Delivery' => 'again'])->assertStatus(202)->getContent());

        $this->assertCount(1, $this->replies->awaited, 'A duplicate never waits: it would take the first request\'s wake-up.');
        Queue::assertPushed(StartAutomationRunJob::class, 1);
    }

    public function test_an_async_trigger_never_waits_or_reads_a_kept_answer(): void
    {
        [, , $token]                         = $this->liveWebhook();
        $this->replies->prepared['wh:async'] = new WebhookReply(200, '{"ignored":true}', WebhookReply::TYPE_JSON);

        $this->deliver($token, '{}', ['X-GitHub-Delivery' => 'async'])->assertStatus(202);
        $this->deliver($token, '{}', ['X-GitHub-Delivery' => 'async'])->assertExactJson(['status' => 'duplicate', 'delivery_id' => 'async']);

        $this->assertSame([], $this->replies->awaited);
        $this->assertSame([], $this->replies->found);
        $this->assertSame([], $this->slots->acquired);
    }

    /**
     * The default GitHub-like trigger, waiting for the flow's answer.
     *
     * @return array<string, mixed>
     */
    private function syncConfig(): array
    {
        return [
            'verification'     => 'hmac_sha256',
            'signature_header' => 'X-Hub-Signature-256',
            'signature_prefix' => 'sha256=',
            'timestamp_header' => 'X-Timestamp',
            'signed_payload'   => '{header.X-Timestamp}.{raw}',
            'delivery_id'      => '{header.X-GitHub-Delivery}',
            'response_mode'    => 'sync',
        ];
    }

    /**
     * A published flow with one webhook trigger (GitHub-like: HMAC-SHA256 with a timestamp) and a secret.
     *
     * @param  array<string, mixed>|null  $config
     *
     * @return array{FlowDraft, string, string} the draft, the trigger id, the token
     */
    private function liveWebhook(?array $config = null, bool $active = true): array
    {
        [$draft, $triggerId] = $this->webhookDraft($config, $active);
        $this->publish($draft);

        return [$draft, $triggerId, $this->secretEndpoint($draft, $triggerId)];
    }

    /**
     * @param  array<string, mixed>|null  $config
     *
     * @return array{FlowDraft, string}
     */
    private function webhookDraft(?array $config = null, bool $active = true): array
    {
        $triggerId = mb_strtolower(Str::ulid()->toRfc4122());
        $draft     = FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'        => [],
            'triggers'     => [[
                'id'        => $triggerId,
                'type'      => 'webhook',
                'is_active' => $active,
                'priority'  => 100,
                'config'    => $config ?? [
                    'verification'     => 'hmac_sha256',
                    'signature_header' => 'X-Hub-Signature-256',
                    'signature_prefix' => 'sha256=',
                    'timestamp_header' => 'X-Timestamp',
                    'signed_payload'   => '{header.X-Timestamp}.{raw}',
                    'delivery_id'      => '{header.X-GitHub-Delivery}',
                    'response_mode'    => 'async',
                ],
            ]],
        ]);

        return [$draft, $triggerId];
    }

    private function secretEndpoint(FlowDraft $draft, string $triggerId): string
    {
        $service = $this->app->make(FlowWebhookEndpointService::class);
        $service->setSecret($draft, $triggerId, self::SECRET);

        return FlowWebhookEndpoint::query()->where('trigger_id', $triggerId)->value('token');
    }

    private function publish(FlowDraft $draft): void
    {
        try {
            $this->app->make(PublishFlowService::class)->execute($draft->flow_id);
        } catch (FlowValidationException $exception) {
            $this->fail('Publish refused: ' . json_encode(array_map(static fn ($error): string => "{$error->code} {$error->path}: {$error->message}", $exception->errors)));
        }
    }

    /**
     * A correctly signed request for the default (GitHub-like) trigger.
     *
     * @param  array<string, string>  $headers
     */
    private function deliver(string $token, string $body, array $headers, string $query = ''): TestResponse
    {
        $timestamp = $headers['X-Timestamp'] ?? (string) time();
        $headers += [
            'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET),
            'X-Timestamp'         => $timestamp,
        ];

        return $this->postRaw($token, $body, $headers, $query);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function postRaw(string $token, string $body, array $headers, string $query = ''): TestResponse
    {
        $headers += ['Content-Type' => 'application/json'];

        $server = [];

        foreach ($headers as $name => $value) {
            $key                                                                                      = mb_strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_' . $key] = $value;
        }

        return $this->call('POST', '/hooks/flow/' . $token . $query, [], [], [], $server, $body);
    }
}

/**
 * {@see WebhookDeliveryDedupInterface} in memory: the Redis one has its own test against a real Redis.
 */
final class InMemoryWebhookDeliveryDedup implements WebhookDeliveryDedupInterface
{
    /** @var array<string, true> */
    public array $claimed = [];

    public function claim(string $tenantId, string $triggerId, string $fireKey): bool
    {
        $key = "{$tenantId}:{$triggerId}:{$fireKey}";

        if (isset($this->claimed[$key])) {
            return false;
        }

        $this->claimed[$key] = true;

        return true;
    }

    public function release(string $tenantId, string $triggerId, string $fireKey): void
    {
        unset($this->claimed["{$tenantId}:{$triggerId}:{$fireKey}"]);
    }
}

/**
 * {@see WebhookReplyStoreInterface} in memory: an answer prepared for a fire key is "delivered" before the request
 * waits; the Redis one has its own test against a real Redis.
 */
final class InMemoryWebhookReplyStore implements WebhookReplyStoreInterface
{
    /** @var array<string, WebhookReply> by fire key */
    public array $prepared = [];

    /** @var list<array{string, string, string, int}> */
    public array $awaited = [];

    /** @var list<string> */
    public array $found = [];

    public function keep(string $tenantId, string $triggerId, string $fireKey, WebhookReply $reply): bool
    {
        if (isset($this->prepared[$fireKey])) {
            return false;
        }

        $this->prepared[$fireKey] = $reply;

        return true;
    }

    public function await(string $tenantId, string $triggerId, string $fireKey, int $seconds): ?WebhookReply
    {
        $this->awaited[] = [$tenantId, mb_strtolower($triggerId), $fireKey, $seconds];

        return $this->prepared[$fireKey] ?? null;
    }

    public function find(string $tenantId, string $triggerId, string $fireKey): ?WebhookReply
    {
        $this->found[] = $fireKey;

        return $this->prepared[$fireKey] ?? null;
    }
}

/**
 * {@see WebhookWaitSlotsInterface} in memory: records what was asked and what is still held.
 */
final class InMemoryWebhookWaitSlots implements WebhookWaitSlotsInterface
{
    public bool $full = false;

    /** @var list<array{int, int, int}> */
    public array $acquired = [];

    /** @var array<string, string> slot => tenant */
    public array $held = [];

    public function acquire(string $tenantId, int $installationMax, int $tenantMax, int $holdSeconds): ?string
    {
        $this->acquired[] = [$installationMax, $tenantMax, $holdSeconds];

        if ($this->full) {
            return null;
        }

        $slot              = 'slot-' . count($this->acquired);
        $this->held[$slot] = $tenantId;

        return $slot;
    }

    public function release(string $tenantId, string $slot): void
    {
        unset($this->held[$slot]);
    }
}
