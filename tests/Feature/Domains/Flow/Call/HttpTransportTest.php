<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Call;

use App\Domains\Flow\Call\Transports\HttpTransport;
use Fapost\Foundation\Flow\Call\CallContext;
use Fapost\Foundation\Flow\Call\CallRequest;
use Illuminate\Support\Facades\Http;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Tests\TestCase;

final class HttpTransportTest extends TestCase
{
    public function test_post_with_body_returns_success_with_parsed_json(): void
    {
        Http::fake([
            'api.example.com/users' => Http::response(['id' => 'user-1'], 201, ['Content-Type' => 'application/json']),
        ]);

        $transport = $this->app->make(HttpTransport::class);

        $result = $transport->execute(
            new CallRequest(
                target: 'POST https://api.example.com/users',
                parameters: [
                    'body.first_name' => 'Иван',
                    'body.email'      => 'i@example.com',
                ],
            ),
            $this->context(),
        );

        $this->assertTrue($result->success);
        $this->assertSame(['id' => 'user-1'], $result->payload);
        $this->assertSame(201, $result->metadata['status_code']);
    }

    public function test_4xx_response_returns_error_under_default_policy(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'not_found'], 404, ['Content-Type' => 'application/json']),
        ]);

        $transport = $this->app->make(HttpTransport::class);

        $result = $transport->execute(
            new CallRequest(target: 'GET https://api.example.com/missing'),
            $this->context(),
        );

        $this->assertFalse($result->success);
        $this->assertSame('http_4xx', $result->errorCode);
        $this->assertSame(['error' => 'not_found'], $result->payload);
    }

    public function test_4xx_response_returns_success_when_policy_is_2xx_or_4xx(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'not_found'], 404, ['Content-Type' => 'application/json']),
        ]);

        $transport = $this->app->make(HttpTransport::class);

        $result = $transport->execute(
            new CallRequest(
                target: 'GET https://api.example.com/missing',
                options: ['success_when' => '2xx_or_4xx'],
            ),
            $this->context(),
        );

        $this->assertTrue($result->success);
        $this->assertSame(404, $result->metadata['status_code']);
    }

    public function test_raw_body_is_sent_verbatim_and_wins_over_body_params(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $transport = $this->app->make(HttpTransport::class);

        $raw    = '{"nested":{"id":7},"tags":["a","b"]}';
        $result = $transport->execute(
            new CallRequest(
                target: 'POST https://api.example.com/raw',
                parameters: ['body.ignored' => 'x'],
                options: ['body_raw' => $raw],
            ),
            $this->context(),
        );

        $this->assertTrue($result->success);
        Http::assertSent(static fn ($request): bool => $request->body() === $raw);
    }

    public function test_response_headers_are_exposed_in_metadata(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json', 'X-Trace' => 'abc-123']),
        ]);

        $transport = $this->app->make(HttpTransport::class);

        $result = $transport->execute(
            new CallRequest(target: 'GET https://api.example.com/thing'),
            $this->context(),
        );

        $this->assertArrayHasKey('headers', $result->metadata);
        $this->assertSame('abc-123', $result->metadata['headers']['X-Trace']);
    }

    public function test_idempotency_key_header_set_from_context(): void
    {
        Http::fake();

        $transport = $this->app->make(HttpTransport::class);
        $transport->execute(
            new CallRequest(target: 'POST https://api.example.com/users'),
            $this->context(idempotencyKey: 'session-1:node-X:1'),
        );

        Http::assertSent(static fn ($request): bool => 'session-1:node-X:1' === $request->header('Idempotency-Key')[0]);
    }

    public function test_invalid_target_returns_error(): void
    {
        $transport = $this->app->make(HttpTransport::class);

        $result = $transport->execute(
            new CallRequest(target: 'WRONG https://api.example.com/'),
            $this->context(),
        );

        $this->assertFalse($result->success);
        $this->assertSame('invalid_target', $result->errorCode);
    }

    public function test_private_address_literal_is_refused_with_egress_denied_and_nothing_is_sent(): void
    {
        Http::fake();

        $result = $this->app->make(HttpTransport::class)->execute(
            new CallRequest(target: 'GET http://10.0.0.5:6379/'),
            $this->context(),
        );

        $this->assertFalse($result->success);
        $this->assertSame('egress_denied', $result->errorCode);
        $this->assertSame(['host' => '10.0.0.5', 'reason' => 'private'], $result->metadata);
        Http::assertNothingSent();
    }

    public function test_host_name_resolving_to_a_private_address_is_refused_and_carries_no_resolved_ip(): void
    {
        Http::fake();
        $this->hostResolver->with('crm.example.com', ['10.0.0.5']);

        $result = $this->app->make(HttpTransport::class)->execute(
            new CallRequest(target: 'GET https://crm.example.com/api'),
            $this->context(),
        );

        $this->assertSame('egress_denied', $result->errorCode);
        $this->assertSame(['host' => 'crm.example.com', 'reason' => 'private'], $result->metadata);
        $this->assertStringNotContainsString('10.0.0.5', json_encode($result->metadata, JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }

    public function test_redirect_from_a_public_host_to_cloud_metadata_is_refused_on_the_second_hop(): void
    {
        Http::fake([
            'api.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        ]);

        $result = $this->app->make(HttpTransport::class)->execute(
            new CallRequest(target: 'GET https://api.example.com/start'),
            $this->context(),
        );

        $this->assertSame('egress_denied', $result->errorCode);
        $this->assertSame(['host' => '169.254.169.254', 'reason' => 'metadata'], $result->metadata);
        Http::assertSentCount(1);
    }

    public function test_redirect_to_a_host_name_with_a_private_address_is_refused(): void
    {
        Http::fake([
            'api.example.com/*' => Http::response('', 301, ['Location' => 'https://rebind.example.com/']),
        ]);
        $this->hostResolver->with('rebind.example.com', ['127.0.0.1']);

        $result = $this->app->make(HttpTransport::class)->execute(
            new CallRequest(target: 'GET https://api.example.com/start'),
            $this->context(),
        );

        $this->assertSame('egress_denied', $result->errorCode);
        $this->assertSame('loopback', $result->metadata['reason']);
        Http::assertSentCount(1);
    }

    public function test_redirect_to_another_public_host_is_still_followed(): void
    {
        Http::fake([
            'api.example.com/*' => Http::response('', 302, ['Location' => 'https://cdn.example.com/final']),
            'cdn.example.com/*' => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $result = $this->app->make(HttpTransport::class)->execute(
            new CallRequest(target: 'GET https://api.example.com/start'),
            $this->context(),
        );

        $this->assertTrue($result->success);
        $this->assertSame(['ok' => true], $result->payload);
        $this->assertContains('cdn.example.com', $this->hostResolver->lookups);
    }

    public function test_an_endless_redirect_chain_stops_after_five_hops(): void
    {
        $hits = 0;
        Http::fake(function () use (&$hits) {
            $hits++;

            return Http::response('', 302, ['Location' => 'https://api.example.com/again']);
        });

        $result = $this->app->make(HttpTransport::class)->execute(
            new CallRequest(target: 'GET https://api.example.com/start'),
            $this->context(),
        );

        // The first request plus five followed redirects; the sixth redirect is not followed.
        $this->assertSame(6, $hits);
        $this->assertFalse($result->success);
        $this->assertSame('transport_failure', $result->errorCode);
    }

    public function test_the_refusal_is_logged_as_a_warning_without_the_url_path_or_query(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $this->app->instance(LoggerInterface::class, $logger);

        $this->app->make(HttpTransport::class)->execute(
            new CallRequest(target: 'GET http://10.0.0.5/private/path?token=s3cret', parameters: ['query.key' => 'k3y']),
            $this->context(),
        );

        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertSame('t-1', $logger->records[0]['context']['tenant_id']);
        $this->assertSame('10.0.0.5', $logger->records[0]['context']['host']);
        $this->assertSame('private', $logger->records[0]['context']['reason']);

        $logged = json_encode($logger->records, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('s3cret', $logged);
        $this->assertStringNotContainsString('k3y', $logged);
        $this->assertStringNotContainsString('private/path', $logged);
    }

    public function test_a_name_that_does_not_resolve_is_still_a_transport_failure(): void
    {
        Http::fake();
        $this->hostResolver->with('nowhere.example.com', []);

        $result = $this->app->make(HttpTransport::class)->execute(
            new CallRequest(target: 'GET https://nowhere.example.com/'),
            $this->context(),
        );

        $this->assertSame('transport_failure', $result->errorCode);
        $this->assertSame(['nowhere.example.com'], $this->hostResolver->lookups);
        $this->assertSame([], array_filter(Http::recorded()->all(), static fn (array $pair): bool => null !== $pair[1]));
    }

    /**
     * No Http::fake: this goes through the real Guzzle curl handler, so it fails if the
     * middleware is not installed in the production stack. Without the guard the first
     * request would end in a refused connection (transport_failure) instead.
     */
    public function test_real_stack_refuses_loopback_before_any_connection_is_opened(): void
    {
        $transport = $this->app->make(HttpTransport::class);

        $literal = $transport->execute(new CallRequest(target: 'GET http://127.0.0.1:9/'), $this->context());
        $this->assertSame('egress_denied', $literal->errorCode);

        $this->hostResolver->with('rebind.example.com', ['127.0.0.1']);
        $named = $transport->execute(new CallRequest(target: 'GET http://rebind.example.com:9/'), $this->context());
        $this->assertSame('egress_denied', $named->errorCode);
        $this->assertSame('loopback', $named->metadata['reason']);
    }

    private function context(string $idempotencyKey = 'session-1:node-1:1'): CallContext
    {
        return new CallContext(
            tenantId: 't-1',
            contactId: 'c-1',
            sessionId: 'session-1',
            nodeId: 'node-1',
            idempotencyKey: $idempotencyKey,
        );
    }
}
