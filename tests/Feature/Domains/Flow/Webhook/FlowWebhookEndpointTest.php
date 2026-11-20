<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Webhook;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Audit\Models\ActivityEntry;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowWebhookEndpoint;
use App\Domains\Flow\Services\FlowDraftService;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Flow\Services\SaveDraftService;
use App\Domains\Flow\Webhook\FlowWebhookEndpointService;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;

/**
 * The builder's side of a webhook trigger: making its URL, setting its secret (shown once, never stored in the
 * trigger's config), and losing both when a publish deletes the trigger.
 */
final class FlowWebhookEndpointTest extends FeatureTestCase
{
    private const array CONFIG = [
        'verification'     => 'hmac_sha256',
        'signature_header' => 'X-Signature',
        'signature_prefix' => 'sha256=',
        'delivery_id'      => '{header.X-Delivery-Id}',
        'response_mode'    => 'async',
    ];

    private string $tenantId;

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: $this->tenantId, schemaName: 'main'));

        $this->assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId, 'default_language' => 'en']);
        $this->app->make(CurrentAssistantInterface::class)->set($this->assistant);

        $this->seed(TenantAclSeeder::class);
    }

    public function test_an_editor_makes_the_url_once_and_it_is_served_on_the_tenant_host(): void
    {
        [$draft, $triggerId] = $this->webhookDraft();
        $editor              = $this->user(Permission::ManageFlowDefinitions);

        $first = $this->actingAs($editor)->postJson($this->endpointUrl($draft, $triggerId))->assertOk()->json('data');
        $again = $this->actingAs($editor)->postJson($this->endpointUrl($draft, $triggerId))->assertOk()->json('data');

        $this->assertSame($first['url'], $again['url']);
        $this->assertMatchesRegularExpression('#/hooks/flow/[A-Za-z0-9_-]{43}$#', $first['url']);
        $this->assertFalse($first['has_secret']);
        $this->assertSame(1, FlowWebhookEndpoint::query()->count());
        $this->assertSame(1, ActivityEntry::query()->where('description', 'flow.webhook_url_created')->count(), 'Issuing the URL is recorded once.');
    }

    public function test_the_url_needs_the_permission_to_edit_the_flow(): void
    {
        [$draft, $triggerId] = $this->webhookDraft();

        $this->actingAs($this->user())->postJson($this->endpointUrl($draft, $triggerId))->assertForbidden();
        $this->assertSame(0, FlowWebhookEndpoint::query()->count());
    }

    public function test_deleting_the_flow_deletes_its_webhook_urls_and_secrets(): void
    {
        [$draft, $triggerId] = $this->webhookDraft();
        $this->publish($draft);
        $this->app->make(FlowWebhookEndpointService::class)->generateSecret($draft, $triggerId);
        [$other, $otherId] = $this->webhookDraft();
        $this->app->make(FlowWebhookEndpointService::class)->ensure($other, $otherId);

        $this->app->make(FlowDraftService::class)->delete($draft->fresh());

        $this->assertSame([$otherId], FlowWebhookEndpoint::query()->pluck('trigger_id')->map(static fn ($id): string => mb_strtolower((string) $id))->all());
    }

    public function test_the_secret_needs_the_publish_and_automation_permissions_and_is_shown_only_when_generated(): void
    {
        [$draft, $triggerId] = $this->webhookDraft();
        $url                 = $this->endpointUrl($draft, $triggerId) . '/secret';

        $this->actingAs($this->user(Permission::ManageFlowDefinitions))->postJson($url)->assertForbidden();
        // Publishing alone does not hand an outside system the means to start the flow.
        $this->actingAs($this->user(Permission::ManageFlowDefinitions, Permission::PublishFlow))->postJson($url)->assertForbidden();
        $this->assertSame(0, FlowWebhookEndpoint::query()->whereNotNull('secret')->count());

        $publisher = $this->user(Permission::ManageFlowDefinitions, Permission::PublishFlow, Permission::ManageAutomations);
        $generated = $this->actingAs($publisher)->postJson($url)->assertOk()->json('data');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $generated['secret']);
        $this->assertTrue($generated['has_secret']);

        $endpoint = FlowWebhookEndpoint::query()->firstOrFail();
        $this->assertSame($generated['secret'], $endpoint->secret);
        $this->assertStringNotContainsString($generated['secret'], (string) DB::table('flow_webhook_endpoints')->value('secret'), 'Stored encrypted.');
        $this->assertArrayNotHasKey('secret', $endpoint->toArray());

        // A replacement: a pasted secret is stored and never echoed; the old one stops working.
        $pasted = $this->actingAs($publisher)->postJson($url, ['secret' => 'provider-issued-secret-123'])->assertOk()->json('data');
        $this->assertNull($pasted['secret']);
        $this->assertSame('provider-issued-secret-123', $endpoint->fresh()->secret);

        $this->actingAs($publisher)->postJson($url, ['secret' => 'short'])->assertUnprocessable();

        $this->assertSame(2, ActivityEntry::query()->where('description', 'flow.webhook_secret_rotated')->count());
    }

    public function test_the_builder_shows_the_url_and_whether_there_is_a_secret_but_never_the_secret(): void
    {
        [$draft, $triggerId] = $this->webhookDraft();
        $publisher           = $this->user(Permission::ManageFlowDefinitions, Permission::PublishFlow, Permission::ManageAutomations);
        $secret              = $this->actingAs($publisher)->postJson($this->endpointUrl($draft, $triggerId) . '/secret')->json('data.secret');

        $response = $this->actingAs($publisher)->get("/builder/flows/{$draft->flow_id}")->assertOk();

        $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where("flow.webhookEndpoints.{$triggerId}.has_secret", true)
            ->has("flow.webhookEndpoints.{$triggerId}.url")
            ->missing("flow.webhookEndpoints.{$triggerId}.secret")
            ->etc());
        $this->assertStringNotContainsString($secret, (string) $response->getContent());
    }

    public function test_a_trigger_the_draft_does_not_list_as_a_webhook_or_another_flows_trigger_is_not_found(): void
    {
        [$draft, $triggerId] = $this->webhookDraft();
        [$other, $otherId]   = $this->webhookDraft();
        $publisher           = $this->user(Permission::ManageFlowDefinitions, Permission::PublishFlow, Permission::ManageAutomations);
        $this->actingAs($publisher)->postJson($this->endpointUrl($other, $otherId))->assertOk();

        // The other flow's trigger id, asked for through this flow: not found, and its endpoint untouched.
        $this->actingAs($publisher)->postJson("/builder/flows/{$draft->flow_id}/triggers/{$otherId}/webhook")->assertNotFound();
        $this->actingAs($publisher)->postJson("/builder/flows/{$draft->flow_id}/triggers/{$otherId}/webhook/secret")->assertNotFound();

        // Even when this draft lists the same id: the endpoint stays with the flow it was made for.
        $draft->forceFill(['triggers' => [...$draft->triggers, $this->trigger($otherId)]])->save();
        $this->actingAs($publisher)->postJson("/builder/flows/{$draft->flow_id}/triggers/{$otherId}/webhook/secret")->assertNotFound();
        $this->assertFalse(FlowWebhookEndpoint::query()->where('trigger_id', $otherId)->firstOrFail()->hasSecret());

        $unknown = mb_strtolower(Str::ulid()->toRfc4122());
        $this->actingAs($publisher)->postJson("/builder/flows/{$draft->flow_id}/triggers/{$unknown}/webhook")->assertNotFound();
    }

    public function test_a_draft_save_drops_a_secret_from_a_webhook_config_and_publish_refuses_the_old_shape(): void
    {
        [$draft, $triggerId] = $this->webhookDraft();

        $this->app->make(SaveDraftService::class)->execute($draft->flow_id, $draft->nodes, [], [
            $this->trigger($triggerId, ['method' => 'POST', 'path' => '/old', 'secret' => 'plain-text']),
        ], $draft->draft_version);

        $stored = $draft->fresh()->triggers[0]['config'];
        $this->assertArrayNotHasKey('secret', $stored);

        try {
            $this->app->make(PublishFlowService::class)->execute($draft->flow_id);
            $this->fail('The old webhook config shape should not publish.');
        } catch (FlowValidationException $exception) {
            $this->assertSame('invalid_trigger_config', $exception->errors[0]->code);
        }
    }

    public function test_a_publish_that_deletes_a_live_webhook_trigger_or_changes_its_type_deletes_its_endpoint(): void
    {
        $kept    = mb_strtolower(Str::ulid()->toRfc4122());
        $removed = mb_strtolower(Str::ulid()->toRfc4122());
        $retyped = mb_strtolower(Str::ulid()->toRfc4122());
        $pending = mb_strtolower(Str::ulid()->toRfc4122());

        $draft = $this->draftWith([$this->trigger($kept), $this->trigger($removed), $this->trigger($retyped)]);
        $this->publish($draft);

        // A trigger only in the draft, its URL made in advance.
        $draft->forceFill(['triggers' => [...$draft->fresh()->triggers, $this->trigger($pending)]])->save();

        $publisher = $this->user(Permission::ManageFlowDefinitions, Permission::PublishFlow, Permission::ManageAutomations);

        foreach ([$kept, $removed, $retyped, $pending] as $id) {
            $this->actingAs($publisher)->postJson($this->endpointUrl($draft, $id))->assertOk();
        }

        $draft->forceFill(['triggers' => [
            $this->trigger($kept),
            ['id' => $retyped, 'type' => 'schedule', 'is_active' => true, 'priority' => 100, 'config' => ['cron' => '0 9 * * *', 'timezone' => 'UTC']],
            $this->trigger($pending),
        ]])->save();
        $this->publish($draft);

        $left = FlowWebhookEndpoint::query()->pluck('trigger_id')->map(static fn ($id): string => mb_strtolower((string) $id))->sort()->values()->all();
        $this->assertSame(collect([$kept, $pending])->sort()->values()->all(), $left);
    }

    /**
     * @return array{FlowDraft, string}
     */
    private function webhookDraft(): array
    {
        $triggerId = mb_strtolower(Str::ulid()->toRfc4122());

        return [$this->draftWith([$this->trigger($triggerId)]), $triggerId];
    }

    /**
     * @param  list<array<string, mixed>>  $triggers
     */
    private function draftWith(array $triggers): FlowDraft
    {
        return FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'        => [],
            'triggers'     => $triggers,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $config
     *
     * @return array<string, mixed>
     */
    private function trigger(string $id, ?array $config = null): array
    {
        return ['id' => $id, 'type' => 'webhook', 'is_active' => true, 'priority' => 100, 'config' => $config ?? self::CONFIG];
    }

    private function endpointUrl(FlowDraft $draft, string $triggerId): string
    {
        return "/builder/flows/{$draft->flow_id}/triggers/{$triggerId}/webhook";
    }

    private function publish(FlowDraft $draft): void
    {
        try {
            $this->app->make(PublishFlowService::class)->execute($draft->flow_id);
        } catch (FlowValidationException $exception) {
            $this->fail('Publish refused: ' . json_encode(array_map(static fn ($error): string => "{$error->code} {$error->path}: {$error->message}", $exception->errors)));
        }
    }

    private function user(Permission ...$permissions): User
    {
        $user = User::factory()->createOne();
        $user->givePermissionTo(...array_map(static fn (Permission $permission): string => $permission->value, $permissions));
        $user->assistants()->attach((string) $this->assistant->getKey());

        return $user;
    }
}
