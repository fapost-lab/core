<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Ops;

use App\Domains\Tenancy\Contracts\WebhookRegistryReaderInterface;
use App\Domains\Webhook\Services\IngressSpecResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class GatewayDoctorCommandTest extends TestCase
{
    private int $drift = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('webhook.base_url', 'https://app.example.com');
        config()->set('webhook.ingress.driver', 'gateway');
        config()->set('webhook.ingress.gateway_url', 'https://gw.example.com');
        config()->set('database.redis.options.prefix', 'fapost_');

        $this->drift = 0;
        $drift       = &$this->drift;

        $reader = Mockery::mock(WebhookRegistryReaderInterface::class);
        $reader->shouldReceive('countIngressDrift')->andReturnUsing(static function () use (&$drift): int {
            return $drift;
        });
        $this->app->instance(WebhookRegistryReaderInterface::class, $reader);
    }

    public function test_reports_a_healthy_setup(): void
    {
        Http::fake(['gw.example.com/healthz' => Http::response('ok', 200)]);
        $this->publishedSpecsMatch();

        $this->artisan('gateway:doctor')
            ->expectsOutputToContain('Gateway connectivity check')
            ->expectsOutputToContain('Ingress driver')
            ->expectsOutputToContain('https://gw.example.com')
            ->expectsOutputToContain('fapost_')
            ->expectsOutputToContain('published, matches adapter')
            ->expectsOutputToContain('Gateway health')
            ->expectsOutputToContain('all on the configured ingress')
            ->expectsOutputToContain('Everything checks out.')
            ->assertExitCode(0);
    }

    public function test_a_gateway_answering_an_error_is_a_problem(): void
    {
        Http::fake(['gw.example.com/healthz' => Http::response('boom', 503)]);
        $this->publishedSpecsMatch();

        $this->artisan('gateway:doctor')
            ->expectsOutputToContain('The gateway answered 503 at https://gw.example.com/healthz.')
            ->expectsOutputToContain('1 problem(s) found.')
            ->assertExitCode(1);
    }

    public function test_an_unreachable_gateway_is_only_a_warning(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('cURL error 7: refused');
        });
        $this->publishedSpecsMatch();

        $this->artisan('gateway:doctor')
            ->expectsOutputToContain('Could not reach https://gw.example.com/healthz: cURL error 7: refused')
            ->expectsOutputToContain('Everything checks out.')
            ->assertExitCode(0);
    }

    public function test_the_url_option_overrides_the_configured_gateway(): void
    {
        Http::fake(['other.example.com/healthz' => Http::response('ok', 200)]);
        $this->publishedSpecsMatch();

        $this->artisan('gateway:doctor', ['--url' => 'https://other.example.com/'])
            ->expectsOutputToContain('Gateway health')
            ->assertExitCode(0);

        Http::assertSent(static fn ($request): bool => 'https://other.example.com/healthz' === $request->url());
    }

    public function test_a_missing_spec_is_a_problem(): void
    {
        Http::fake(['gw.example.com/healthz' => Http::response('ok', 200)]);
        Redis::shouldReceive('ping')->andReturn(true);
        Redis::shouldReceive('get')->andReturn(null);

        $this->artisan('gateway:doctor')
            ->expectsOutputToContain('No spec published for telegram. Run php artisan ops:ingress-specs-publish.')
            ->assertExitCode(1);
    }

    public function test_a_malformed_url_option_is_a_warning_not_a_crash(): void
    {
        $this->publishedSpecsMatch();

        $this->artisan('gateway:doctor', ['--url' => 'http://gw host'])
            ->expectsOutputToContain('Could not reach http://gw host/healthz')
            ->assertExitCode(0);
    }

    public function test_a_spec_that_differs_from_the_adapter_is_a_problem(): void
    {
        Http::fake(['gw.example.com/healthz' => Http::response('ok', 200)]);
        Redis::shouldReceive('ping')->andReturn(true);
        Redis::shouldReceive('get')->andReturn(json_encode([
            'v' => 1, 'scheme' => 'none', 'parameter' => '', 'prefix' => '', 'idempotency' => 'x',
        ], JSON_THROW_ON_ERROR));

        $this->artisan('gateway:doctor')
            ->expectsOutputToContain("The published spec for telegram differs from the adapter's.")
            ->assertExitCode(1);
    }

    public function test_an_unreadable_spec_registry_reads_as_nothing_published(): void
    {
        Http::fake(['gw.example.com/healthz' => Http::response('ok', 200)]);
        Redis::shouldReceive('ping')->andReturn(true);
        Redis::shouldReceive('get')->andThrow(new RuntimeException('redis gone'));

        $this->artisan('gateway:doctor')
            ->expectsOutputToContain('No spec published for telegram.')
            ->assertExitCode(1);
    }

    public function test_an_unreachable_redis_is_a_problem(): void
    {
        Http::fake(['gw.example.com/healthz' => Http::response('ok', 200)]);
        Redis::shouldReceive('ping')->andThrow(new RuntimeException('connection refused'));
        Redis::shouldReceive('get')->andReturn(null);

        $this->artisan('gateway:doctor')
            ->expectsOutputToContain('Redis is unreachable: connection refused')
            ->assertExitCode(1);
    }

    public function test_the_laravel_driver_is_a_warning_and_skips_the_gateway_probe(): void
    {
        config()->set('webhook.ingress.driver', 'laravel');
        config()->set('webhook.ingress.gateway_url', '');
        Http::fake();
        $this->publishedSpecsMatch();

        $this->artisan('gateway:doctor')
            ->expectsOutputToContain('The driver is not "gateway"')
            ->expectsOutputToContain('Everything checks out.')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_an_empty_gateway_url_is_a_problem(): void
    {
        config()->set('webhook.ingress.gateway_url', '');
        Http::fake();
        $this->publishedSpecsMatch();

        $this->artisan('gateway:doctor')
            ->expectsOutputToContain('WEBHOOK_GATEWAY_URL is empty')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_channels_behind_the_configured_ingress_are_listed(): void
    {
        Http::fake(['gw.example.com/healthz' => Http::response('ok', 200)]);
        $this->publishedSpecsMatch();
        $this->drift = 3;

        $this->artisan('gateway:doctor')
            ->expectsOutputToContain('Behind: telegram')
            ->expectsOutputToContain('ops:ingress-migrate --apply')
            ->assertExitCode(0);
    }

    private function publishedSpecsMatch(): void
    {
        $expected = $this->app->make(IngressSpecResolver::class)->specFor('telegram');

        Redis::shouldReceive('ping')->andReturn(true);
        Redis::shouldReceive('get')->andReturn(json_encode($expected, JSON_THROW_ON_ERROR));
    }
}
