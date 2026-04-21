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
        config()->set('webhook.base_url', 'https://hooks.example.com/octane/');

        $url = app(WebhookUrlGenerator::class)->forChannel('telegram', 'hash-123');

        $this->assertSame('https://hooks.example.com/octane/webhook/telegram/hash-123', $url);
    }

    public function test_it_requires_configured_webhook_base_url(): void
    {
        config()->set('webhook.base_url', '');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook base URL is not configured.');

        app(WebhookUrlGenerator::class)->forChannel('telegram', 'hash-123');
    }
}
