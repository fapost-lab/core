<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Call;

use App\Domains\Flow\Call\CallTester;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CallTesterTest extends TestCase
{
    public function test_runs_http_call_with_rendered_templates_and_returns_shape(): void
    {
        Http::fake([
            'api.example.com/users/7' => Http::response(
                ['data' => ['id' => 7, 'name' => 'Bob']],
                200,
                ['Content-Type' => 'application/json', 'X-Trace' => 'abc'],
            ),
        ]);

        $result = app(CallTester::class)->run(
            [
                'transport'         => 'http',
                'target'            => 'GET https://api.example.com/users/{{flow.uid}}',
                'transport_options' => ['success_when' => '2xx'],
            ],
            ['flow.uid' => '7'],
        );

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status_code']);
        $this->assertSame(['data' => ['id' => 7, 'name' => 'Bob']], $result['body']);
        $this->assertSame('abc', $result['headers']['X-Trace']);
        $this->assertNull($result['error_code']);
    }

    public function test_unknown_transport_returns_error_result(): void
    {
        $result = app(CallTester::class)->run(['transport' => 'nope', 'target' => 'GET https://x'], []);

        $this->assertFalse($result['success']);
        $this->assertSame('unknown_transport', $result['error_code']);
    }

    public function test_builder_test_call_to_a_private_address_reports_egress_denied(): void
    {
        Http::fake();

        $result = app(CallTester::class)->run(
            ['transport' => 'http', 'target' => 'GET http://169.254.169.254/latest/meta-data/'],
            [],
        );

        $this->assertFalse($result['success']);
        $this->assertSame('egress_denied', $result['error_code']);
        Http::assertNothingSent();
    }

    public function test_each_test_click_sends_its_own_idempotency_key(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);

        $config = ['transport' => 'http', 'target' => 'GET https://api.example.com/ping'];
        app(CallTester::class)->run($config, []);
        app(CallTester::class)->run($config, []);

        $keys = Http::recorded()->map(static fn (array $pair): string => $pair[0]->header('Idempotency-Key')[0])->all();

        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);
        $this->assertStringStartsWith('test:builder:', $keys[0]);
    }
}
