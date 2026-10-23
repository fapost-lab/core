<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Health;

use App\Domains\Health\Enums\HealthState;
use App\Domains\Health\Probes\GatewayProbe;
use App\Domains\Tenancy\Contracts\WebhookRegistryReaderInterface;
use App\Domains\Webhook\Services\IngressSpecResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class GatewayProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('webhook.base_url', 'https://app.example.com');
        config()->set('webhook.ingress.driver', 'gateway');
        config()->set('webhook.ingress.gateway_url', 'https://gw.example.com');

        $this->channelsBehind(0);
    }

    public function test_up_when_healthz_answers_with_wiring_details(): void
    {
        Http::fake(['gw.example.com/healthz' => Http::response('ok')]);
        $this->publishedSpecs('matches');
        $this->channelsBehind(2);

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Up, $result->state);
        $this->assertSame('gateway', $result->details['driver']);
        $this->assertSame(['telegram' => 'matches'], $result->details['specs']);
        $this->assertSame(['telegram' => 2], $result->details['drift']);
    }

    public function test_drifted_channels_and_missing_specs_do_not_turn_a_healthy_gateway_down(): void
    {
        Http::fake(['gw.example.com/healthz' => Http::response('ok')]);
        $this->publishedSpecs('missing');
        $this->channelsBehind(5);

        $this->assertSame(HealthState::Up, $this->probe()->check()->state);
    }

    public function test_down_when_the_gateway_answers_an_error(): void
    {
        Http::fake(['gw.example.com/healthz' => Http::response('', 503)]);
        $this->publishedSpecs('matches');

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Down, $result->state);
        $this->assertSame(503, $result->details['status']);
    }

    public function test_unknown_when_the_gateway_cannot_be_reached(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('cURL error 28: timed out');
        });
        $this->publishedSpecs('matches');

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Unknown, $result->state);
        $this->assertNull($result->details['status']);
    }

    public function test_unreadable_wiring_is_null_in_the_details_not_a_failure(): void
    {
        Http::fake(['gw.example.com/healthz' => Http::response('ok')]);
        Redis::shouldReceive('get')->andThrow(new RuntimeException('redis down'));

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Up, $result->state);
        $this->assertNull($result->details['specs']);
    }

    public function test_the_laravel_driver_is_disabled(): void
    {
        config()->set('webhook.ingress.driver', 'laravel');
        Http::fake();

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Disabled, $result->state);
        Http::assertNothingSent();
    }

    public function test_the_gateway_driver_without_an_address_is_unknown(): void
    {
        config()->set('webhook.ingress.gateway_url', '');
        Http::fake();

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Unknown, $result->state);
        $this->assertStringContainsString('WEBHOOK_GATEWAY_URL', $result->reason);
        Http::assertNothingSent();
    }

    public function test_a_malformed_address_is_unknown_not_an_exception(): void
    {
        $result = $this->probe()->probeEndpoint('http://gw host');

        $this->assertSame(HealthState::Unknown, $result->state);
        $this->assertNotSame('', $result->details['error']);
    }

    public function test_probe_endpoint_asks_the_given_address(): void
    {
        Http::fake(['other.example.com/healthz' => Http::response('ok')]);

        $result = $this->probe()->probeEndpoint('https://other.example.com/');

        $this->assertSame(HealthState::Up, $result->state);
        $this->assertSame('https://other.example.com/healthz', $result->details['url']);
    }

    private function probe(): GatewayProbe
    {
        return $this->app->make(GatewayProbe::class);
    }

    private function publishedSpecs(string $state): void
    {
        $expected = $this->app->make(IngressSpecResolver::class)->specFor('telegram');

        Redis::shouldReceive('get')->andReturn('missing' === $state ? null : json_encode($expected, JSON_THROW_ON_ERROR));
    }

    private function channelsBehind(int $count): void
    {
        $reader = Mockery::mock(WebhookRegistryReaderInterface::class);
        $reader->shouldReceive('countIngressDrift')->andReturn($count);
        $this->app->instance(WebhookRegistryReaderInterface::class, $reader);
    }
}
