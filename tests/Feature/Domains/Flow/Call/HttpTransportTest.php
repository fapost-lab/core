<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Call;

use App\Domains\Flow\Call\Transports\HttpTransport;
use Fapost\Foundation\Flow\Call\CallContext;
use Fapost\Foundation\Flow\Call\CallRequest;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpTransportTest extends TestCase
{
    public function test_post_with_body_returns_success_with_parsed_json(): void
    {
        Http::fake([
            'api.example.com/users' => Http::response(['id' => 'user-1'], 201, ['Content-Type' => 'application/json']),
        ]);

        $transport = new HttpTransport(app(HttpFactory::class));

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

        $transport = new HttpTransport(app(HttpFactory::class));

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

        $transport = new HttpTransport(app(HttpFactory::class));

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

        $transport = new HttpTransport(app(HttpFactory::class));

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

        $transport = new HttpTransport(app(HttpFactory::class));

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

        $transport = new HttpTransport(app(HttpFactory::class));
        $transport->execute(
            new CallRequest(target: 'POST https://api.example.com/users'),
            $this->context(idempotencyKey: 'session-1:node-X:1'),
        );

        Http::assertSent(static function ($request): bool {
            return 'session-1:node-X:1' === $request->header('Idempotency-Key')[0];
        });
    }

    public function test_invalid_target_returns_error(): void
    {
        $transport = new HttpTransport(app(HttpFactory::class));

        $result = $transport->execute(
            new CallRequest(target: 'WRONG https://api.example.com/'),
            $this->context(),
        );

        $this->assertFalse($result->success);
        $this->assertSame('invalid_target', $result->errorCode);
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
