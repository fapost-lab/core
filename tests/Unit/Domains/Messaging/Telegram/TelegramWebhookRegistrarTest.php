<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Messaging\Telegram;

use App\Domains\Channels\Telegram\TelegramBotApiClientFactory;
use App\Domains\Channels\Telegram\TelegramWebhookRegistrar;
use FAPost\Foundation\Channel\WebhookRegistrationPayload;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TelegramWebhookRegistrarTest extends TestCase
{
    public function test_register_calls_set_webhook_with_expected_url(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $registrar = new TelegramWebhookRegistrar(new TelegramBotApiClientFactory());

        $registrar->register(new WebhookRegistrationPayload(
            token: 'bot-token',
            secretToken: 'secret-1',
            webhookPublicHash: 'hash-123',
            config: [],
        ));

        Http::assertSent(static fn (Request $request): bool => 'https://api.telegram.org/botbot-token/setWebhook' === (string) $request->url()
                && route('webhook.handle', ['channel' => 'telegram', 'hash' => 'hash-123']) === $request['url']
                && 'secret-1' === $request['secretToken']);
    }

    public function test_deregister_calls_delete_webhook(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $registrar = new TelegramWebhookRegistrar(new TelegramBotApiClientFactory());

        $registrar->deregister(new WebhookRegistrationPayload(
            token: 'bot-token',
            secretToken: 'secret-1',
            webhookPublicHash: 'hash-123',
            config: [],
        ));

        Http::assertSent(static fn (Request $request): bool => 'https://api.telegram.org/botbot-token/deleteWebhook'
            === (string) $request->url());
    }
}
