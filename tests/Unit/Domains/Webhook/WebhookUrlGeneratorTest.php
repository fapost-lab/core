<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Webhook\Services\WebhookUrlGenerator;
use InvalidArgumentException;
use Tests\TestCase;

final class WebhookUrlGeneratorTest extends TestCase
{
    public function test_it_generates_webhook_url_from_configured_base_url(): void
    {
        config()->set('webhook.base_url', 'https://hooks.example.com');

        $url = app(WebhookUrlGenerator::class)->forChannel('telegram', 'hash-123');

        $this->assertSame('https://hooks.example.com/webhook/telegram/hash-123', $url);
    }

    public function test_it_preserves_base_path_from_configured_base_url(): void
    {
        config()->set('webhook.base_url', 'https://hooks.example.com/ingress/');

        $url = app(WebhookUrlGenerator::class)->forChannel('telegram', 'hash-123');

        $this->assertSame('https://hooks.example.com/ingress/webhook/telegram/hash-123', $url);
    }

    public function test_it_requires_configured_webhook_base_url(): void
    {
        config()->set('webhook.base_url', '');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook base URL is not configured.');

        app(WebhookUrlGenerator::class)->forChannel('telegram', 'hash-123');
    }

    public function test_gateway_driver_points_listed_platforms_at_the_gateway_host(): void
    {
        $this->useGateway();

        $this->assertSame(
            'https://webhook.example.com/webhook/telegram/hash-123',
            app(WebhookUrlGenerator::class)->forChannel('telegram', 'hash-123'),
        );
    }

    /**
     * The path must be identical on both hosts: that is what keeps switching a
     * base-URL swap and lets the gateway proxy straight through to Laravel.
     */
    public function test_gateway_and_laravel_urls_differ_only_by_host(): void
    {
        config()->set('webhook.base_url', 'https://app.example.com');
        $viaLaravel = app(WebhookUrlGenerator::class)->forChannel('telegram', 'hash-123');

        $this->useGateway();
        $viaGateway = app(WebhookUrlGenerator::class)->forChannel('telegram', 'hash-123');

        $this->assertSame(
            (string) parse_url($viaLaravel, PHP_URL_PATH),
            (string) parse_url($viaGateway, PHP_URL_PATH),
        );
    }

    /**
     * Eligibility comes from the adapters, not from configuration: WhatsApp's
     * adapter publishes no ingress spec, so no environment setting can send its
     * webhooks to a gateway that would have nothing to verify them with.
     */
    public function test_platform_without_an_ingress_spec_stays_on_the_laravel_route(): void
    {
        $this->useGateway();

        $this->assertSame(
            'https://app.example.com/webhook/whatsapp/hash-123',
            app(WebhookUrlGenerator::class)->forChannel('whatsapp', 'hash-123'),
        );
    }

    public function test_gateway_driver_without_gateway_url_falls_back_to_laravel_route(): void
    {
        $this->useGateway(gatewayUrl: '');

        $this->assertSame(
            'https://app.example.com/webhook/telegram/hash-123',
            app(WebhookUrlGenerator::class)->forChannel('telegram', 'hash-123'),
        );
    }

    /**
     * A typo in the environment must not aim live traffic at a host that may not
     * exist — an unrecognized driver degrades to the always-valid Laravel route.
     */
    public function test_unknown_driver_falls_back_to_laravel_route(): void
    {
        $this->useGateway(driver: 'gatewey');

        $this->assertSame(
            'https://app.example.com/webhook/telegram/hash-123',
            app(WebhookUrlGenerator::class)->forChannel('telegram', 'hash-123'),
        );
    }

    public function test_uses_gateway_reports_the_routing_decision(): void
    {
        $this->useGateway();

        $generator = app(WebhookUrlGenerator::class);

        $this->assertTrue($generator->usesGateway('telegram'));
        $this->assertFalse($generator->usesGateway('whatsapp'));
    }

    private function useGateway(
        string $driver = 'gateway',
        string $gatewayUrl = 'https://webhook.example.com',
    ): void {
        config()->set('webhook.base_url', 'https://app.example.com');
        config()->set('webhook.ingress.driver', $driver);
        config()->set('webhook.ingress.gateway_url', $gatewayUrl);
    }
}
