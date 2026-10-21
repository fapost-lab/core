<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Channels;

use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Telegram\TelegramBotIdentityStore;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\FeatureTestCase;

/**
 * The bot name Telegram reports is a managed setting of the channel's config: written past the model's events, so the
 * webhook is not synchronized again because the bot told its name, and without losing the settings around it.
 */
final class TelegramBotIdentityStoreTest extends FeatureTestCase
{
    public function test_it_adds_the_name_to_the_config_and_keeps_the_other_settings(): void
    {
        $channel = $this->channel(['allowed_updates' => ['message'], 'max_connections' => 12]);

        (new TelegramBotIdentityStore())->saveUsername((string) $channel->getKey(), 'acme_bot');

        $this->assertSame(['allowed_updates' => ['message'], 'max_connections' => 12, 'bot_username' => 'acme_bot'], $channel->refresh()->config);
    }

    public function test_it_replaces_a_name_and_writes_into_an_empty_config(): void
    {
        $channel = $this->channel([]);
        $store   = new TelegramBotIdentityStore();

        $store->saveUsername((string) $channel->getKey(), 'first_bot');
        $this->assertSame(['bot_username' => 'first_bot'], $channel->refresh()->config);

        $store->saveUsername((string) $channel->getKey(), 'second_bot');
        $this->assertSame(['bot_username' => 'second_bot'], $channel->refresh()->config);

        $store->saveUsername((string) $channel->getKey(), null);
        $this->assertSame(['bot_username' => null], $channel->refresh()->config);
    }

    public function test_it_does_not_synchronize_the_provider_again(): void
    {
        $channel = $this->channel(['max_connections' => 40]);

        Bus::fake([SyncChannelWebhookJob::class]);

        (new TelegramBotIdentityStore())->saveUsername((string) $channel->getKey(), 'acme_bot');

        Bus::assertNotDispatchedSync(SyncChannelWebhookJob::class);
        Bus::assertNotDispatched(SyncChannelWebhookJob::class);
    }

    public function test_a_channel_that_is_gone_is_ignored(): void
    {
        (new TelegramBotIdentityStore())->saveUsername('01a15dd8-d7c7-ea7c-137a-66006c89ad64', 'acme_bot');

        $this->assertSame(0, Channel::query()->count());
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function channel(array $config): Channel
    {
        return Channel::withoutEvents(fn (): Channel => Channel::factory()->create(['config' => $config]));
    }
}
