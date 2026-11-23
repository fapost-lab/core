<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Webhook;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\DTOs\FlowValidationResultDto;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Flow\Services\ValidateFlowService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * Checking a `webhook_response` node before publish: it publishes under a webhook trigger that gives no contact, a bad
 * status or body is refused, and a flow with no trigger that waits for the answer is warned, not refused.
 */
final class WebhookResponseNodeValidationTest extends FeatureTestCase
{
    private string $tenantId;

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: $this->tenantId, schemaName: 'main'));

        $this->assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId, 'default_language' => 'en']);
        $this->app->make(CurrentAssistantInterface::class)->set($this->assistant);
    }

    public function test_a_flow_with_the_node_under_a_waiting_webhook_trigger_publishes_without_warnings(): void
    {
        $draft = $this->draft($this->respond(['status' => 201, 'body_type' => 'json', 'body' => ['ok' => true]]), 'sync');

        $result = $this->validate($draft);

        $this->assertTrue($result->valid, json_encode($result->errors, JSON_THROW_ON_ERROR));
        $this->assertSame([], $result->warnings);

        try {
            $this->assertSame(1, $this->app->make(PublishFlowService::class)->execute($draft->flow_id)->version);
        } catch (FlowValidationException $exception) {
            $this->fail('Publish refused: ' . json_encode(array_map(static fn ($error): string => $error->code, $exception->errors)));
        }
    }

    public function test_without_a_trigger_that_waits_the_node_is_a_warning_only(): void
    {
        $result = $this->validate($this->draft($this->respond(['status' => 200, 'body_type' => 'text', 'text' => 'ok']), 'async'));

        $this->assertTrue($result->valid);
        $this->assertSame(['webhook_response_without_sync_trigger'], array_map(static fn ($warning): string => $warning->code, $result->warnings));
        $this->assertSame('nodes.respond', $result->warnings[0]->path);
    }

    public function test_an_api_trigger_that_waits_also_takes_the_node_and_one_that_does_not_is_a_warning(): void
    {
        $this->assertSame([], $this->validate($this->draft($this->respond(['status' => 200, 'body_type' => 'text', 'text' => 'ok']), 'sync', 'api'))->warnings);

        $result = $this->validate($this->draft($this->respond(['status' => 200, 'body_type' => 'text', 'text' => 'ok']), 'async', 'api'));
        $this->assertSame(['webhook_response_without_sync_trigger'], array_map(static fn ($warning): string => $warning->code, $result->warnings));
    }

    public function test_a_status_out_of_range_or_a_body_of_the_wrong_kind_is_refused(): void
    {
        $codes = fn (array $config): array => array_map(
            static fn ($error): string => $error->code,
            $this->validate($this->draft($this->respond($config), 'sync'))->errors,
        );

        $this->assertSame(['webhook_response_invalid_status'], $codes(['status' => 500, 'body_type' => 'json']));
        $this->assertSame(['webhook_response_invalid_status'], $codes(['status' => 'ok', 'body_type' => 'json']));
        $this->assertSame(['webhook_response_invalid_body_type'], $codes(['status' => 200, 'body_type' => 'xml']));
        $this->assertSame(['webhook_response_invalid_body_type'], $codes(['status' => 200, 'body_type' => ['json']]));
        $this->assertSame(['webhook_response_invalid_body'], $codes(['status' => 200, 'body_type' => 'json', 'body' => 'not json']));
        $this->assertSame([], $codes(['status' => '204', 'body_type' => 'text', 'text' => '']));
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return array<string, mixed>
     */
    private function respond(array $config): array
    {
        return ['id' => 'respond', 'type' => 'webhook_response', 'version' => 1, 'config' => $config];
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function draft(array $node, string $responseMode, string $triggerType = 'webhook'): FlowDraft
    {
        return FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => [$node, ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'        => [['id' => 'e1', 'from' => 'respond', 'to' => 'end', 'handle' => 'default']],
            'triggers'     => [[
                'id'        => mb_strtolower(Str::ulid()->toRfc4122()),
                'type'      => $triggerType,
                'is_active' => true,
                'priority'  => 100,
                'config'    => 'api' === $triggerType
                    ? ['response_mode' => $responseMode]
                    : ['verification' => 'header_equals', 'signature_header' => 'X-Webhook-Secret', 'response_mode' => $responseMode],
            ]],
        ]);
    }

    private function validate(FlowDraft $draft): FlowValidationResultDto
    {
        return $this->app->make(ValidateFlowService::class)->execute(
            nodes: $draft->nodes,
            triggers: $draft->triggers,
            flowId: $draft->flow_id,
            edges: $draft->edges,
        );
    }
}
