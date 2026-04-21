<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use InvalidArgumentException;

final class WebhookUrlGenerator
{
    public function forChannel(string $channel, string $hash): string
    {
        $baseUrl = (string) config('webhook.base_url');

        if ('' === $baseUrl) {
            throw new InvalidArgumentException('Webhook base URL is not configured.');
        }

        return mb_rtrim($baseUrl, '/') . '/webhook/' . rawurlencode($channel) . '/' . rawurlencode($hash);
    }
}
