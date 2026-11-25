<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Call;

use App\Domains\Flow\Call\CallCredentialResolver;
use App\Domains\Flow\Call\CallTransportRegistry;
use App\Domains\Flow\Call\Transports\HttpTransport;
use App\Domains\Flow\Handlers\CallNodeHandler;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\State\Variables\VariableResolver;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\Flow\Call\CallContext;
use Fapost\Foundation\Flow\Call\CallRequest;
use Fapost\Foundation\Flow\Call\CallResult;
use Fapost\Foundation\Flow\Call\CallTransportInterface;
use Fapost\Foundation\Flow\Enums\NodeConfigIssueSeverity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakeUsageMeter;
use Tests\Support\InMemoryCallExecutionLedger;
use Tests\Support\InMemoryCredentialStore;
use Tests\Support\UsageGates;
use Tests\TestCase;

/**
 * The `credential_id` of the `call` node: resolved before the quota is spent, passed to the transport on every
 * attempt, never rendered as a template, and refused with a code the flow log shows.
 */
final class CallNodeCredentialTest extends TestCase
{
    private const string SECRET = 'secret-value-123';

    private const string ID = '0197c0de-0000-7000-8000-000000000001';

    private InMemoryCredentialStore $store;

    private InMemoryCallExecutionLedger $ledger;

    private FakeUsageMeter $meter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store  = new InMemoryCredentialStore();
        $this->ledger = new InMemoryCallExecutionLedger();
        $this->meter  = FakeUsageMeter::allowing();
        $this->store->add($this->secret(self::ID));
    }

    public function test_the_credential_reaches_the_transport_and_the_secret_is_in_neither_parameters_nor_options(): void
    {
        $transport = $this->transport();
        $handler   = $this->handler($transport);

        $result = $handler->execute($this->node(['credential_id' => self::ID, 'parameters' => ['body.a' => '1']]), [], $this->context('k:1'));

        $this->assertSame('success', $result->sourceHandle);
        $this->assertSame(self::ID, $result->metadata['credential_id']);
        $this->assertCount(1, $transport->requests);

        $request = $transport->requests[0];

        $this->assertInstanceOf(CredentialSecret::class, $request->credential);
        $this->assertSame(self::ID, $request->credential->id);
        $this->assertStringNotContainsString(self::SECRET, json_encode([$request->target, $request->parameters, $request->options], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(self::SECRET, json_encode($result->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_a_node_without_a_credential_sends_none_and_reads_no_store(): void
    {
        $transport = $this->transport();

        foreach ([[], ['credential_id' => ''], ['credential_id' => null], ['credential_id' => 42]] as $extra) {
            $this->handler($transport)->execute($this->node($extra), [], $this->context('k:' . count($transport->requests)));
        }

        $this->assertCount(4, $transport->requests);

        foreach ($transport->requests as $request) {
            $this->assertNull($request->credential);
        }

        $this->assertSame(0, $this->store->reads);
    }

    public function test_the_credential_id_is_not_a_template(): void
    {
        $transport = $this->transport();

        $result = $this->handler($transport)->execute(
            $this->node(['credential_id' => '{{flow.pick}}']),
            ['flow' => ['pick' => self::ID]],
            $this->context('k:1'),
        );

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('credential_not_found', $result->metadata['error_code']);
        $this->assertSame('{{flow.pick}}', $result->metadata['credential_id']);
        $this->assertSame([], $transport->requests);
    }

    public function test_a_missing_credential_takes_the_error_handle_and_spends_no_quota(): void
    {
        $transport = $this->transport();

        $result = $this->handler($transport)->execute($this->node(['credential_id' => 'nope']), [], $this->context('k:1'));

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('credential_not_found', $result->metadata['error_code']);
        $this->assertSame('credential_not_found: nope', $result->errorMessage);
        $this->assertSame([], $transport->requests);
        $this->assertSame([], $this->meter->units);
        $this->assertSame([], $this->ledger->attempts);
    }

    public function test_an_unreadable_credential_is_unavailable(): void
    {
        $this->store->unavailable[] = self::ID;
        $transport                  = $this->transport();

        $result = $this->handler($transport)->execute($this->node(['credential_id' => self::ID]), [], $this->context('k:1'));

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('credential_unavailable', $result->metadata['error_code']);
        $this->assertSame([], $transport->requests);
        $this->assertSame([], $this->meter->units);
    }

    public function test_only_the_http_transport_takes_a_credential(): void
    {
        $transport = $this->transport('handler');

        $result = $this->handler($transport)->execute(
            $this->node(['transport' => 'handler', 'credential_id' => self::ID]),
            [],
            $this->context('k:1'),
        );

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('credential_unsupported', $result->metadata['error_code']);
        $this->assertSame([], $transport->requests);
        $this->assertSame([], $this->meter->units);
        $this->assertSame(0, $this->store->reads);
    }

    public function test_a_replay_of_a_successful_call_does_not_resolve_the_credential(): void
    {
        $transport = $this->transport();
        $this->ledger->complete(UsageGates::TENANT_ID, 'k:1:call-1', CallResult::ok(['id' => 'u1']));

        $result = $this->handler($transport)->execute($this->node(['credential_id' => self::ID]), [], $this->context('k:1'));

        $this->assertSame('success', $result->sourceHandle);
        $this->assertTrue($result->metadata['deduplicated']);
        $this->assertSame(0, $this->store->reads);
        $this->assertSame([], $transport->requests);
    }

    public function test_a_retry_sends_the_credential_again(): void
    {
        Sleep::fake();

        $transport = $this->transport('http', [CallResult::error('http_5xx', null, ['status_code' => 503]), CallResult::ok(['id' => 'u1'], ['status_code' => 200])]);

        $result = $this->handler($transport)->execute(
            $this->node(['credential_id' => self::ID, 'retry' => ['max_attempts' => 2]]),
            [],
            $this->context('k:1'),
        );

        $this->assertSame('success', $result->sourceHandle);
        $this->assertCount(2, $transport->requests);
        $this->assertSame(self::ID, $transport->requests[0]->credential?->id);
        $this->assertSame(self::ID, $transport->requests[1]->credential?->id);
        $this->assertSame(1, $this->store->reads, 'The credential is read once, not once per attempt.');

        Sleep::fake(false);
    }

    public function test_publish_validation_of_the_credential(): void
    {
        $handler = $this->handler($this->transport());

        $codes = static fn (array $config): array => array_map(
            static fn ($issue): string => $issue->code,
            $handler->validate($config),
        );

        $this->assertSame([], $codes($this->node(['credential_id' => self::ID])));
        $this->assertSame([], $codes($this->node([])));
        $this->assertSame([], $codes($this->node(['credential_id' => ''])));
        $this->assertSame(['call_invalid_credential'], $codes($this->node(['credential_id' => ['a']])));
        $this->assertSame(['call_credential_not_found'], $codes($this->node(['credential_id' => 'nope'])));
        $this->assertSame(['call_credential_unsupported'], $codes($this->node(['credential_id' => self::ID, 'transport' => 'handler'])));

        $issues = $handler->validate($this->node(['credential_id' => 'nope']));

        $this->assertSame(NodeConfigIssueSeverity::Error, $issues[0]->severity);
        $this->assertSame('credential_id', $issues[0]->field);
    }

    public function test_a_literal_plain_http_target_with_a_credential_blocks_publishing(): void
    {
        $handler = $this->handler($this->transport());

        $codes = static fn (array $config): array => array_map(
            static fn ($issue): string => $issue->code,
            $handler->validate($config),
        );

        $this->assertSame(
            ['call_credential_insecure_target'],
            $codes($this->node(['credential_id' => self::ID, 'target' => 'POST http://api.example.com/x'])),
        );
        $this->assertSame([], $codes($this->node(['credential_id' => self::ID, 'target' => 'POST {{vars.url}}'])));
        $this->assertSame([], $codes($this->node(['credential_id' => self::ID, 'target' => 'POST https://api.example.com/x'])));
        // Without a credential a plain http target is the author's business.
        $this->assertSame([], $codes($this->node(['target' => 'POST http://api.example.com/x'])));

        $issues = $handler->validate($this->node(['credential_id' => self::ID, 'target' => 'GET HTTP://api.example.com/x']));

        $this->assertSame('call_credential_insecure_target', $issues[0]->code);
        $this->assertSame('target', $issues[0]->field);
        $this->assertSame(NodeConfigIssueSeverity::Error, $issues[0]->severity);
    }

    public function test_a_secret_the_target_echoes_is_masked_in_the_ledger_and_the_saved_variable(): void
    {
        Http::fake(['api.example.com/*' => Http::response(
            ['you_sent' => 'Bearer ' . self::SECRET],
            200,
            ['Content-Type' => 'application/json', 'X-Echo' => self::SECRET],
        )]);

        $registry = new CallTransportRegistry();
        $registry->register($this->app->make(HttpTransport::class));
        $handler = new CallNodeHandler($registry, new TemplateRenderer(), new VariableResolver(), UsageGates::quota($this->meter), $this->ledger, new CallCredentialResolver($this->store));

        $result = $handler->execute(
            $this->node(['credential_id' => self::ID, 'save_to_variable' => ['name' => 'response', 'storage' => 'session']]),
            [],
            $this->context('k:echo'),
        );

        $this->assertSame('success', $result->sourceHandle);
        $this->assertSame(['you_sent' => 'Bearer ***'], $result->stateChanges['flow.response']['body'] ?? null);
        $this->assertSame('***', $result->stateChanges['flow.response']['headers']['X-Echo'] ?? null);
        $this->assertStringNotContainsString(self::SECRET, json_encode($result->stateChanges, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(self::SECRET, json_encode($result->trace, JSON_THROW_ON_ERROR));

        $this->assertCount(1, $this->ledger->completed);

        $stored = array_values($this->ledger->completed)[0];

        $this->assertSame(['you_sent' => 'Bearer ***'], $stored->payload);
        $this->assertSame('***', $stored->metadata['headers']['X-Echo']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($stored, JSON_THROW_ON_ERROR));
    }

    private function secret(string $id): CredentialSecret
    {
        return new CredentialSecret(UsageGates::TENANT_ID, $id, 'bearer', 'v1', [], ['api.example.com'], ['token' => self::SECRET]);
    }

    /**
     * @param  list<CallResult>  $results
     */
    private function transport(string $id = 'http', array $results = []): CallTransportInterface
    {
        return new class ($id, $results) implements CallTransportInterface {
            /**
             * @var list<CallRequest>
             */
            public array $requests = [];

            /**
             * @param  list<CallResult>  $results
             */
            public function __construct(private readonly string $id, private array $results)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function execute(CallRequest $request, CallContext $context): CallResult
            {
                $this->requests[] = $request;

                return array_shift($this->results) ?? CallResult::ok(['id' => 'u1'], ['status_code' => 200]);
            }
        };
    }

    private function handler(CallTransportInterface $transport): CallNodeHandler
    {
        $registry = new CallTransportRegistry();
        $registry->register($transport);

        return new CallNodeHandler($registry, new TemplateRenderer(), new VariableResolver(), UsageGates::quota($this->meter), $this->ledger, new CallCredentialResolver($this->store));
    }

    /**
     * @param  array<string, mixed>  $extra
     *
     * @return array<string, mixed>
     */
    private function node(array $extra = []): array
    {
        return [
            'id'     => 'call-1',
            'config' => [
                'transport' => 'http',
                'target'    => 'GET https://api.example.com/x',
                ...$extra,
            ],
        ];
    }

    private function context(string $engineKey): NodeExecutionContext
    {
        return new NodeExecutionContext(
            tenantId: UsageGates::TENANT_ID,
            contactId: 'contact-1',
            sessionId: 'session-1',
            nodeId: 'call-1',
            idempotencyKey: $engineKey,
            platform: 'telegram',
        );
    }
}
