<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Api;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Admission\RefusalReason;
use App\Domains\Flow\Api\FlowApiTriggerReceiver;
use App\Domains\Flow\Contracts\FlowTriggerStateRepositoryInterface;
use App\Domains\Flow\DTOs\FlowRunOrigin;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Flow\Webhook\WebhookDeliveryDedupInterface;
use App\Domains\Flow\Webhook\WebhookReply;
use App\Domains\Flow\Webhook\WebhookReplyStoreInterface;
use App\Domains\Flow\Webhook\WebhookWaitSlotsInterface;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\ApiTokenService;
use App\Domains\Staff\Support\IssuedApiToken;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Jobs\Flow\StartAutomationRunJob;
use Carbon\CarbonImmutable;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeTenantAccessMode;
use Tests\Support\Flow\InMemoryWebhookDeliveryDedup;
use Tests\Support\Flow\InMemoryWebhookReplyStore;
use Tests\Support\Flow\InMemoryWebhookWaitSlots;

/**
 * `POST /api/v1/flow-triggers/{trigger}/runs`: a tenant API token with `StartFlowRuns` starts a run of a published flow
 * through its API trigger — once per `Idempotency-Key` and token — and everything else is refused before a run is
 * queued.
 */
final class FlowApiTriggerTest extends FeatureTestCase
{
    private string $tenantId;

    private User $admin;

    private Assistant $assistant;

    private IssuedApiToken $token;

    private InMemoryWebhookDeliveryDedup $dedup;

    private InMemoryWebhookReplyStore $replies;

    private InMemoryWebhookWaitSlots $slots;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $tenant         = Tenant::query()->firstOrFail();
        $this->tenantId = (string) $tenant->getKey();
        $this->app->make(TenantContextInterface::class)->set($tenant);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(RoleEnum::Admin->value);

        $this->assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId, 'default_language' => 'en']);
        $this->app->make(CurrentAssistantInterface::class)->set($this->assistant);

        $this->dedup = new InMemoryWebhookDeliveryDedup();
        $this->app->instance(WebhookDeliveryDedupInterface::class, $this->dedup);

        $this->replies = new InMemoryWebhookReplyStore();
        $this->app->instance(WebhookReplyStoreInterface::class, $this->replies);

        $this->slots = new InMemoryWebhookWaitSlots();
        $this->app->instance(WebhookWaitSlotsInterface::class, $this->slots);

        $this->token = $this->issue('CRM', [Permission::StartFlowRuns->value]);

        Queue::fake();
    }

    public function test_a_call_queues_one_run_with_the_key_of_its_token_and_answers_202(): void
    {
        [$draft, $triggerId] = $this->liveApiTrigger();

        $this->callTrigger($triggerId, '{"order":{"id":42},"note":"hi"}', ['Idempotency-Key' => 'order-42', 'X-Custom' => 'not for the flow'])
            ->assertStatus(202)
            ->assertExactJson(['status' => 'accepted', 'request_id' => 'order-42'])
            ->assertHeaderMissing('Set-Cookie');

        Queue::assertPushed(StartAutomationRunJob::class, 1);
        Queue::assertPushed(StartAutomationRunJob::class, function (StartAutomationRunJob $job) use ($draft, $triggerId): bool {
            $api = $job->initialState['flow']['api'];

            $this->assertSame($this->tenantId, $job->tenantId);
            $this->assertSame($draft->flow_id, $job->flowId);
            $this->assertSame($triggerId, mb_strtolower($job->triggerId));
            $this->assertSame('api:' . $this->tokenId() . ':order-42', $job->fireKey);
            $this->assertSame((string) $this->assistant->getKey(), $job->assistantId);
            $this->assertSame(['order' => ['id' => 42], 'note' => 'hi'], $api['body']);
            $this->assertSame('order-42', $api['request_id']);
            $this->assertSame(['id' => $this->tokenId(), 'name' => 'CRM'], $api['token']);
            $this->assertNotEmpty($api['received_at']);
            $this->assertSame(['body', 'request_id', 'token', 'received_at'], array_keys($api), 'No header of the call reaches the flow.');

            return true;
        });
    }

    public function test_a_repeated_key_is_a_duplicate_for_its_token_and_a_new_run_for_another(): void
    {
        [, $triggerId] = $this->liveApiTrigger();
        $other         = $this->issue('ERP', [Permission::StartFlowRuns->value]);

        $this->callTrigger($triggerId, '{}', ['Idempotency-Key' => 'same'])->assertStatus(202);
        $this->callTrigger($triggerId, '{}', ['Idempotency-Key' => 'same'])
            ->assertOk()
            ->assertExactJson(['status' => 'duplicate', 'request_id' => 'same']);
        $this->callTrigger($triggerId, '{}', ['Idempotency-Key' => 'same'], $other)->assertStatus(202);

        Queue::assertPushed(StartAutomationRunJob::class, 2);
    }

    public function test_a_call_without_a_key_gets_a_fresh_request_id_and_a_new_run_every_time(): void
    {
        [, $triggerId] = $this->liveApiTrigger();

        $first  = $this->callTrigger($triggerId, '{}')->assertStatus(202)->json('request_id');
        $second = $this->callTrigger($triggerId, '{}')->assertStatus(202)->json('request_id');

        $this->assertNotSame($first, $second);
        $this->assertTrue(Str::isUlid($first));
        Queue::assertPushed(StartAutomationRunJob::class, fn (StartAutomationRunJob $job): bool => 'api:' . $first === $job->fireKey);
        Queue::assertPushed(StartAutomationRunJob::class, 2);
        $this->assertSame([], $this->dedup->claimed);
    }

    public function test_a_long_key_becomes_a_hashed_fire_key_and_is_still_echoed_as_sent(): void
    {
        [, $triggerId] = $this->liveApiTrigger();
        $key           = str_repeat('k', 255);

        $this->callTrigger($triggerId, '{}', ['Idempotency-Key' => $key])
            ->assertStatus(202)
            ->assertJsonPath('request_id', $key);

        Queue::assertPushed(StartAutomationRunJob::class, function (StartAutomationRunJob $job) use ($key): bool {
            $this->assertSame('api:sha256:' . hash('sha256', $this->tokenId() . ':' . $key), $job->fireKey);
            $this->assertLessThanOrEqual(FlowRunOrigin::MAX_FIRE_KEY_LENGTH, mb_strlen($job->fireKey));

            return true;
        });
    }

    public function test_a_key_that_is_empty_too_long_or_not_printable_ascii_is_refused_with_422(): void
    {
        [, $triggerId] = $this->liveApiTrigger();

        foreach (['', str_repeat('k', 256), "tab\there", 'ключ'] as $key) {
            $this->callTrigger($triggerId, '{}', ['Idempotency-Key' => $key])
                ->assertStatus(422)
                ->assertExactJson(['error' => 'invalid_idempotency_key']);
        }

        Queue::assertNothingPushed();
    }

    public function test_a_token_without_the_permission_is_refused_with_403(): void
    {
        [, $triggerId] = $this->liveApiTrigger();
        $settings      = $this->issue('Settings', [Permission::ManageSettings->value]);

        $this->callTrigger($triggerId, '{}', [], $settings)
            ->assertForbidden()
            ->assertExactJson(['error' => 'forbidden']);

        Queue::assertNothingPushed();
    }

    public function test_a_call_without_a_valid_token_is_401_and_a_stopped_tenant_refuses_it(): void
    {
        [, $triggerId] = $this->liveApiTrigger();

        $this->call('POST', '/api/v1/flow-triggers/' . $triggerId . '/runs', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')
            ->assertStatus(401);

        $this->app->instance(TenantAccessModeInterface::class, FakeTenantAccessMode::stopped());
        $this->app->forgetScopedInstances();

        $this->callTrigger($triggerId, '{}')->assertStatus(423);

        Queue::assertNothingPushed();
    }

    public function test_every_trigger_that_cannot_be_called_is_the_same_404(): void
    {
        [, $inactive]           = $this->liveApiTrigger(active: false);
        [, $webhook]            = $this->liveApiTrigger(type: 'webhook');
        [, $unpublished]        = $this->apiDraft();
        [$orphanDraft, $orphan] = $this->liveApiTrigger();
        [, $legacy]             = $this->liveApiTrigger();
        FlowDraft::query()->where('flow_id', $orphanDraft->flow_id)->delete();
        // A row an older validator published: its keys never worked, and it is not served until it is set again.
        FlowTrigger::query()->whereKey($legacy)->update(['config' => json_encode(['route_key' => 'k', 'allowed_sources' => []])]);

        foreach ([$inactive, $webhook, $unpublished, $orphan, $legacy, mb_strtolower(Str::ulid()->toRfc4122())] as $triggerId) {
            $this->callTrigger($triggerId, '{}', ['Idempotency-Key' => 'k'])
                ->assertNotFound()
                ->assertExactJson(['error' => 'not_found']);
        }

        // Not a trigger id at all: never reaches the uuid column.
        $this->callTrigger('not-an-id', '{}')->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_the_frameworks_refusals_are_json_too_whatever_the_client_accepts(): void
    {
        [, $triggerId] = $this->liveApiTrigger();
        config(['api_tokens.per_minute' => 1]);

        $this->callTrigger($triggerId, '{}', ['Accept' => 'text/html'])->assertStatus(202);
        $this->callTrigger($triggerId, '{}', ['Accept' => 'text/html'])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertExactJson(['error' => 'too_many_requests']);

        $this->call('GET', '/api/v1/flow-triggers/' . $triggerId . '/runs', [], [], [], ['HTTP_ACCEPT' => 'text/html'])
            ->assertStatus(405)
            ->assertExactJson(['error' => 'method_not_allowed']);
        $this->call('POST', '/api/v1/flow-triggers/not-an-id/runs', [], [], [], ['HTTP_ACCEPT' => 'text/html'])
            ->assertNotFound()
            ->assertExactJson(['error' => 'not_found']);
    }

    public function test_a_trigger_of_another_tenant_is_not_found(): void
    {
        [, $triggerId] = $this->liveApiTrigger();
        FlowTrigger::query()->whereKey($triggerId)->update(['tenant_id' => (string) Str::uuid()]);

        $this->callTrigger($triggerId, '{}')->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_the_body_must_be_a_json_object_of_at_most_256_kb(): void
    {
        [, $triggerId] = $this->liveApiTrigger();

        $this->callTrigger($triggerId, 'a=1', ['Content-Type' => 'application/x-www-form-urlencoded'])
            ->assertStatus(415)
            ->assertExactJson(['error' => 'unsupported_media_type']);
        $this->callTrigger($triggerId, '{"a":"' . str_repeat('x', FlowApiTriggerReceiver::MAX_BODY_BYTES) . '"}')
            ->assertStatus(413);

        foreach (['', '[]', '[1,2]', '"text"', '42', 'null', '{"a":', "{\"a\":\"\xB1\"}"] as $body) {
            $this->callTrigger($triggerId, $body)
                ->assertStatus(422)
                ->assertExactJson(['error' => 'invalid_json']);
        }

        $this->callTrigger($triggerId, '{}', ['Content-Type' => 'application/vnd.api+json; charset=utf-8'])->assertStatus(202);

        Queue::assertPushed(StartAutomationRunJob::class, 1);
    }

    public function test_a_paused_trigger_answers_503_with_retry_after_and_keeps_the_key_unclaimed(): void
    {
        [, $triggerId] = $this->liveApiTrigger();
        $states        = $this->app->make(FlowTriggerStateRepositoryInterface::class);
        $states->pause($triggerId, RefusalReason::RateBreaker, CarbonImmutable::now());

        $this->callTrigger($triggerId, '{}', ['Idempotency-Key' => 'p-1'])
            ->assertStatus(503)
            ->assertHeader('Retry-After', (string) FlowApiTriggerReceiver::PAUSED_RETRY_AFTER_SECONDS)
            ->assertExactJson(['error' => 'trigger_paused']);

        Queue::assertNothingPushed();
        $this->assertSame([], $this->dedup->claimed, 'The key is not claimed, so a retry after a resume runs.');

        $states->resume($triggerId, CarbonImmutable::now());

        $this->callTrigger($triggerId, '{}', ['Idempotency-Key' => 'p-1'])->assertStatus(202);
        Queue::assertPushed(StartAutomationRunJob::class, 1);
    }

    public function test_a_flow_switched_off_in_the_flows_list_answers_404_and_keeps_the_key_unclaimed(): void
    {
        [$draft, $triggerId] = $this->liveApiTrigger();
        $draft->forceFill(['is_active' => false])->save();

        $this->callTrigger($triggerId, '{}', ['Idempotency-Key' => 'off-1'])
            ->assertNotFound()
            ->assertExactJson(['error' => 'not_found']);

        Queue::assertNothingPushed();
        $this->assertSame([], $this->dedup->claimed, 'The key is not claimed: its retry after the switch-on runs.');

        $draft->forceFill(['is_active' => true])->save();

        $this->callTrigger($triggerId, '{}', ['Idempotency-Key' => 'off-1'])->assertStatus(202);
        Queue::assertPushed(StartAutomationRunJob::class, 1);
    }

    public function test_a_failed_dispatch_gives_the_claim_back(): void
    {
        [, $triggerId] = $this->liveApiTrigger();
        $this->mock(\Illuminate\Contracts\Bus\Dispatcher::class)
            ->shouldReceive('dispatch')
            ->andThrow(new RuntimeException('queue down'));

        $this->withoutExceptionHandling();

        try {
            $this->callTrigger($triggerId, '{}', ['Idempotency-Key' => 'lost']);
            $this->fail('The dispatch failure should surface.');
        } catch (RuntimeException $exception) {
            $this->assertSame('queue down', $exception->getMessage());
        }

        $this->assertSame([], $this->dedup->claimed);
    }

    public function test_a_sync_trigger_answers_with_the_flows_reply_while_holding_a_wait_slot(): void
    {
        [, $triggerId] = $this->liveApiTrigger(config: ['response_mode' => 'sync']);
        $fireKey       = 'api:' . $this->tokenId() . ':sync-1';

        $this->replies->prepared[$fireKey] = new WebhookReply(201, '{"order":"created"}', WebhookReply::TYPE_JSON);

        $response = $this->callTrigger($triggerId, '{}', ['Idempotency-Key' => 'sync-1'])
            ->assertStatus(201)
            ->assertHeader('Content-Type', 'application/json');

        $this->assertSame('{"order":"created"}', $response->getContent());
        $this->assertSame([[$this->tenantId, $triggerId, $fireKey, 30]], $this->replies->awaited);
        $this->assertSame([], $this->slots->held, 'The slot is given back.');

        // A repeat gets the kept answer and never waits.
        $this->assertSame('{"order":"created"}', $this->callTrigger($triggerId, '{}', ['Idempotency-Key' => 'sync-1'])->assertStatus(201)->getContent());
        $this->assertCount(1, $this->replies->awaited);
        Queue::assertPushed(StartAutomationRunJob::class, 1);
    }

    public function test_a_sync_call_without_a_free_slot_or_an_answer_in_time_gets_202(): void
    {
        [, $triggerId] = $this->liveApiTrigger(config: ['response_mode' => 'sync']);

        $this->callTrigger($triggerId, '{}', ['Idempotency-Key' => 'slow'])
            ->assertStatus(202)
            ->assertExactJson(['status' => 'accepted', 'request_id' => 'slow']);

        $this->slots->full = true;
        $this->callTrigger($triggerId, '{}', ['Idempotency-Key' => 'busy'])->assertStatus(202);

        $this->assertCount(1, $this->replies->awaited, 'Without a slot the call does not wait.');
        $this->assertSame([], $this->slots->held);
    }

    public function test_an_async_trigger_never_waits_or_reads_a_kept_answer(): void
    {
        [, $triggerId] = $this->liveApiTrigger();

        $this->callTrigger($triggerId, '{}', ['Idempotency-Key' => 'async'])->assertStatus(202);
        $this->callTrigger($triggerId, '{}', ['Idempotency-Key' => 'async'])->assertExactJson(['status' => 'duplicate', 'request_id' => 'async']);

        $this->assertSame([], $this->replies->awaited);
        $this->assertSame([], $this->replies->found);
        $this->assertSame([], $this->slots->acquired);
    }

    public function test_the_builder_is_given_the_address_and_the_token_screen_only_for_whom_may_open_it(): void
    {
        [$draft] = $this->liveApiTrigger();

        $this->actingAs($this->admin)->get("/builder/flows/{$draft->flow_id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('flow.apiTrigger.runs_url', url('/api/v1/flow-triggers/00000000-0000-0000-0000-000000000000/runs'))
                ->where('flow.apiTrigger.trigger_placeholder', '00000000-0000-0000-0000-000000000000')
                ->where('flow.apiTrigger.tokens_url', route('console.admin.api-tokens.index')));

        $editor = User::factory()->createOne();
        $editor->givePermissionTo(Permission::ManageFlowDefinitions->value);
        $editor->assistants()->attach((string) $this->assistant->getKey());

        $this->actingAs($editor)->get("/builder/flows/{$draft->flow_id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('flow.apiTrigger.tokens_url', null));
    }

    /**
     * @param  list<string>  $abilities
     */
    private function issue(string $name, array $abilities): IssuedApiToken
    {
        return $this->app->make(ApiTokenService::class)->issue($this->admin, $name, $abilities);
    }

    private function tokenId(): string
    {
        return mb_strtolower((string) $this->token->token->getKey());
    }

    /**
     * @param  array<string, mixed>|null  $config
     *
     * @return array{FlowDraft, string}
     */
    private function liveApiTrigger(?array $config = null, bool $active = true, string $type = 'api'): array
    {
        [$draft, $triggerId] = $this->apiDraft($config, $active, $type);

        try {
            $this->app->make(PublishFlowService::class)->execute($draft->flow_id);
        } catch (FlowValidationException $exception) {
            $this->fail('Publish refused: ' . json_encode(array_map(static fn ($error): string => "{$error->code} {$error->path}: {$error->message}", $exception->errors)));
        }

        return [$draft, $triggerId];
    }

    /**
     * @param  array<string, mixed>|null  $config
     *
     * @return array{FlowDraft, string}
     */
    private function apiDraft(?array $config = null, bool $active = true, string $type = 'api'): array
    {
        $triggerId = mb_strtolower(Str::ulid()->toRfc4122());
        $draft     = FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'        => [],
            'triggers'     => [[
                'id'        => $triggerId,
                'type'      => $type,
                'is_active' => $active,
                'priority'  => 100,
                'config'    => 'webhook' === $type
                    ? ['verification' => 'header_equals', 'signature_header' => 'X-Secret']
                    : ($config ?? ['response_mode' => 'async']),
            ]],
        ]);

        return [$draft, $triggerId];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function callTrigger(string $triggerId, string $body, array $headers = [], ?IssuedApiToken $token = null): TestResponse
    {
        // The request guard keeps the token it found for the life of the application; a real request starts anew.
        Auth::forgetGuards();

        $headers += [
            'Authorization' => 'Bearer ' . ($token ?? $this->token)->plain,
            'Content-Type'  => 'application/json',
        ];

        $server = [];

        foreach ($headers as $name => $value) {
            $key                                                                                      = mb_strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_' . $key] = $value;
        }

        return $this->call('POST', '/api/v1/flow-triggers/' . $triggerId . '/runs', [], [], [], $server, $body);
    }
}
