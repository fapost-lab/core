<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Messaging\Telegram;

use App\Domains\Channels\Telegram\Contracts\TelegramBotIdentityStoreInterface;
use App\Domains\Channels\Telegram\TelegramBotApiClientFactory;
use App\Domains\Channels\Telegram\TelegramWebhookRegistrar;
use App\Domains\Webhook\Services\WebhookUrlGenerator;
use FAPost\Foundation\Channel\WebhookRegistrationPayload;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

final class TelegramWebhookRegistrarTest extends TestCase
{
    public function test_register_calls_set_webhook_with_expected_url(): void
    {
        config()->set('webhook.base_url', 'https://hooks.example.com');

        Http::fake([
            'https://api.telegram.org/botbot-token/setWebhook' => Http::response(['ok' => true], 200),
            'https://api.telegram.org/botbot-token/getMe'      => Http::response([
                'ok'     => true,
                'result' => [
                    'username' => 'sample_bot',
                ],
            ], 200),
        ]);

        $identityStore = $this->mock(TelegramBotIdentityStoreInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('saveUsername')->with('channel-1', 'sample_bot')->once();
        });
        $registrar = new TelegramWebhookRegistrar(new TelegramBotApiClientFactory(), new WebhookUrlGenerator('https://hooks.example.com'), $identityStore);

        $registrar->register(new WebhookRegistrationPayload(
            channelId: 'channel-1',
            token: 'bot-token',
            secretToken: 'secret-1',
            webhookPublicHash: 'hash-123',
            config: [
                'allowed_updates' => ['message', 'callback_query'],
                'max_connections' => 80,
            ],
        ));

        Http::assertSent(static fn (Request $request): bool => 'https://api.telegram.org/botbot-token/setWebhook' === (string) $request->url()
                && 'https://hooks.example.com/webhook/telegram/hash-123' === $request['url']
                && 'secret-1' === $request['secret_token']
                && ['message', 'callback_query'] === $request['allowed_updates']
                && 80 === $request['max_connections']);
    }

    public function test_register_uses_default_max_connections_when_config_is_missing(): void
    {
        config()->set('webhook.base_url', 'https://hooks.example.com');

        Http::fake([
            'https://api.telegram.org/botbot-token/setWebhook' => Http::response(['ok' => true], 200),
            'https://api.telegram.org/botbot-token/getMe'      => Http::response([
                'ok'     => true,
                'result' => [
                    'username' => 'sample_bot',
                ],
            ], 200),
        ]);

        $identityStore = $this->mock(TelegramBotIdentityStoreInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('saveUsername')->with('channel-1', 'sample_bot')->once();
        });
        $registrar = new TelegramWebhookRegistrar(new TelegramBotApiClientFactory(), new WebhookUrlGenerator('https://hooks.example.com'), $identityStore);

        $registrar->register(new WebhookRegistrationPayload(
            channelId: 'channel-1',
            token: 'bot-token',
            secretToken: 'secret-1',
            webhookPublicHash: 'hash-123',
            config: [],
        ));

        Http::assertSent(static fn (Request $request): bool => 'https://api.telegram.org/botbot-token/setWebhook' === (string) $request->url()
            && 40 === $request['max_connections']);
    }

    public function test_deregister_calls_delete_webhook(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $registrar = new TelegramWebhookRegistrar(
            new TelegramBotApiClientFactory(),
            new WebhookUrlGenerator('https://hooks.example.com'),
            $this->mock(TelegramBotIdentityStoreInterface::class, function (MockInterface $mock): void {
                $mock->shouldNotReceive('saveUsername');
            }),
        );

        $registrar->deregister(new WebhookRegistrationPayload(
            channelId: 'channel-1',
            token: 'bot-token',
            secretToken: 'secret-1',
            webhookPublicHash: 'hash-123',
            config: [],
        ));

        Http::assertSent(static fn (Request $request): bool => 'https://api.telegram.org/botbot-token/deleteWebhook'
            === (string) $request->url());
    }
}
