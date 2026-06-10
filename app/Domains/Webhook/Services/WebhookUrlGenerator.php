<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use InvalidArgumentException;

final readonly class WebhookUrlGenerator
{
    /**
     * @param  string|null  $baseUrl  Public ingress base URL (config `webhook.base_url`), bound in WebhookServiceProvider.
     */
    public function __construct(
        private ?string $baseUrl = null,
    ) {
    }

    public function forChannel(string $channel, string $hash): string
    {
        $baseUrl = (string)$this->baseUrl;

        if ('' === $baseUrl) {
            throw new InvalidArgumentException('Webhook base URL is not configured.');
        }

        return mb_rtrim($baseUrl, '/') . '/webhook/' . rawurlencode($channel) . '/' . rawurlencode($hash);
    }
}
