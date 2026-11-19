<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Trace;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Call\CallTransportRegistry;
use App\Domains\Flow\Call\Egress\EgressGuardMiddleware;
use App\Domains\Flow\Call\Egress\GuardedHttpClient;
use App\Domains\Flow\Call\Transports\HttpTransport;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\DTOs\FlowRunOrigin;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowSessionHistoryEntry;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Flow\History\HistoryEventType;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * What the node trace of an automation run shows of a `call` node: the rendered request and the response, masked.
 */
final class CallNodeTraceTest extends FeatureTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: $this->tenantId, schemaName: 'main'));

        // The egress guard is built while the database is prepared, before the test double for DNS is bound: rebuild it
        // (and what holds it), so that no name is looked up for real.
        foreach ([EgressGuardMiddleware::class, GuardedHttpClient::class, HttpTransport::class, CallTransportRegistry::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    public function test_the_response_is_part_of_the_output(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['id' => 7, 'name' => 'Anna'], 201, ['X-Trace' => 'abc-123'])]);

        $completed = $this->completed($this->runCall(['target' => 'POST https://api.example.com/users']));

        $output = $completed['output']['node'];

        $this->assertTrue($output['success']);
        $this->assertSame(201, $output['status']);
        $this->assertSame('abc-123', $output['headers']['X-Trace']);
        $this->assertSame(['id' => 7, 'name' => 'Anna'], $output['body']);
        $this->assertSame(1, $output['attempt']);
        $this->assertFalse($output['replayed']);
        $this->assertSame(1, $output['sends']);
        $this->assertSame('success', $completed['handle']);
    }

    public function test_a_failed_call_shows_its_status_and_error_code(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['error' => 'nope'], 404)]);

        $completed = $this->completed($this->runCall(['target' => 'GET https://api.example.com/missing']));

        $output = $completed['output']['node'];

        $this->assertFalse($output['success']);
        $this->assertSame(404, $output['status']);
        $this->assertSame('http_4xx', $output['error_code']);
        $this->assertSame('error', $completed['handle']);
    }

    public function test_the_input_shows_the_rendered_target_and_parameters(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);

        $completed = $this->completed($this->runCall([
            'target'     => 'POST https://api.example.com/users/{{flow.user_id}}',
            'parameters' => ['body.name' => '{{flow.user_name}}', 'query.page' => '2'],
        ], ['flow' => ['user_id' => '42', 'user_name' => 'Anna']]));

        $input = $completed['input']['node'];

        $this->assertSame('http', $input['transport']);
        $this->assertSame('POST https://api.example.com/users/42', $input['target']);
        $this->assertSame('Anna', $input['parameters']['body.name']);
        $this->assertSame('2', $input['parameters']['query.page']);
    }

    public function test_credentials_in_the_target_and_parameters_are_masked_in_the_input(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);

        $completed = $this->completed($this->runCall([
            'target'            => 'GET https://api.example.com/me?access_token=abc123&page=1',
            'parameters'        => ['headers.Authorization' => 'Bearer s3cr3t', 'headers.X-Plain' => 'visible'],
            'transport_options' => ['headers' => ['X-Api-Key' => 'key-value-1']],
        ]));

        $input = $completed['input']['node'];

        $this->assertSame('GET https://api.example.com/me?access_token=***&page=1', $input['target']);
        $this->assertSame('***', $input['parameters']['headers.Authorization']);
        $this->assertSame('visible', $input['parameters']['headers.X-Plain']);
        $this->assertSame('***', $input['options']['headers']['X-Api-Key']);

        $encoded = (string) json_encode($completed, JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString('abc123', $encoded);
        $this->assertStringNotContainsString('s3cr3t', $encoded);
        $this->assertStringNotContainsString('key-value-1', $encoded);
    }

    public function test_a_secret_in_the_response_under_a_sensitive_key_is_masked_in_the_output(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['token' => 'issued-token', 'user' => ['password' => 'pw', 'id' => 5]], 200)]);

        $completed = $this->completed($this->runCall(['target' => 'POST https://api.example.com/login']));

        $this->assertSame('***', $completed['output']['node']['body']['token']);
        $this->assertSame('***', $completed['output']['node']['body']['user']['password']);
        $this->assertSame(5, $completed['output']['node']['body']['user']['id']);
    }

    public function test_the_internal_attempt_number_is_not_shown_in_the_options(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);

        $completed = $this->completed($this->runCall([
            'target'            => 'GET https://api.example.com/x',
            'transport_options' => ['timeout' => 5],
            'retry'             => ['max_attempts' => 2],
        ]));

        $this->assertSame(['timeout' => 5], $completed['input']['node']['options']);
        $this->assertArrayNotHasKey('attempt_number', $completed['input']['node']['options']);
    }

    public function test_a_bearer_token_parameter_is_masked_in_the_input(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);

        $completed = $this->completed($this->runCall([
            'target'     => 'GET https://api.example.com/me',
            'parameters' => ['auth.bearer' => 'bearer-secret-value'],
        ]));

        $this->assertStringNotContainsString('bearer-secret-value', (string) json_encode($completed));
    }

    public function test_the_payload_is_left_out_when_the_setting_is_off(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['id' => 1], 200)]);
        Config::set('flow.trace.payloads', false);

        $completed = $this->completed($this->runCall(['target' => 'GET https://api.example.com/x']));

        $this->assertArrayNotHasKey('input', $completed);
        $this->assertArrayNotHasKey('output', $completed);
        $this->assertSame('success', $completed['handle']);
    }

    public function test_a_huge_response_body_is_truncated_to_a_marker_and_the_envelope_stays_storable(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['blob' => str_repeat('x', 1_200_000), 'id' => 3], 200)]);

        $completed = $this->completed($this->runCall(['target' => 'GET https://api.example.com/big']));

        $this->assertTrue($completed['truncated']);
        $this->assertStringStartsWith('[truncated, ', $completed['output']['node']['body']['blob']);
        $this->assertSame(3, $completed['output']['node']['body']['id']);
        $this->assertLessThanOrEqual(1_048_576, mb_strlen((string) json_encode($completed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '8bit'));
    }

    public function test_a_conversation_call_node_leaves_no_payload_in_the_trace(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['id' => 1], 200)]);

        $completed = $this->completed($this->runCall(['target' => 'GET https://api.example.com/x'], origin: FlowRunOrigin::conversation()));

        $this->assertArrayNotHasKey('input', $completed);
        $this->assertArrayNotHasKey('output', $completed);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function runCall(array $config, array $state = [], ?FlowRunOrigin $origin = null): FlowSession
    {
        $assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId]);
        $contact   = Contact::factory()->forTenant($this->tenantId)->create();
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Call trace',
            'nodes'     => [['id' => 'call', 'type' => 'call', 'version' => 1, 'config' => ['transport' => 'http', ...$config]]],
            'edges'     => [],
            'is_active' => true,
        ]);

        return $this->app->make(FlowEngineInterface::class)->start($definition, $contact, $state, $origin ?? FlowRunOrigin::automation());
    }

    /**
     * @return array<string, mixed>
     */
    private function completed(FlowSession $session): array
    {
        $row = FlowSessionHistoryEntry::query()
            ->where('session_id', $session->getKey())
            ->where('event_type', HistoryEventType::NodeCompleted->value)
            ->sole();

        return $row->metadata;
    }
}
