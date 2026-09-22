<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\TelegramBotApiClientFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Bot API origin comes from `services.telegram.api_base_url`, so a load test
 * can point real Telegram traffic at a local stub.
 */
final class TelegramBotApiClientFactoryTest extends TestCase
{
    public function test_clients_default_to_the_real_bot_api(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['id' => 1]])]);

        $this->app->make(TelegramBotApiClientFactory::class)->make('tok')->getMe();

        Http::assertSent(fn (Request $request): bool => 'https://api.telegram.org/bottok/getMe' === $request->url());
    }

    public function test_clients_use_the_configured_api_base_url(): void
    {
        config(['services.telegram.api_base_url' => 'http://127.0.0.1:8099/']);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['id' => 1]])]);

        $this->app->make(TelegramBotApiClientFactory::class)->make('tok')->getMe();

        Http::assertSent(fn (Request $request): bool => 'http://127.0.0.1:8099/bottok/getMe' === $request->url());
    }
}
