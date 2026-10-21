<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Channels;

use App\Domains\Channels\ConnectorChannels;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Services\ChannelPublicLinks;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use Fapost\Foundation\Channel\Settings\InvalidManagedSettingException;
use Fapost\Foundation\Channel\Settings\ManagedChannelSettingsInterface;
use Fapost\Foundation\Channel\WebhookRegistrationPayload;
use Fapost\Foundation\Connector\ConnectorChannel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;
use Tests\Fixtures\Connectors\Echo\EchoChannelProfile;
use Tests\Fixtures\Connectors\Echo\EchoInboundAdapter;
use Tests\Fixtures\Connectors\Echo\EchoSender;
use Tests\Fixtures\Connectors\Echo\EchoWebhookRegistrar;

/**
 * The port an integration reports the managed settings of its channel through: values are held to the schema, merged
 * into the stored `config` in the schema's order, and written past the model's events, so the webhook is not
 * synchronized again because the integration told a name.
 */
final class ChannelManagedSettingsTest extends FeatureTestCase
{
    public function test_it_adds_a_managed_value_and_keeps_the_other_settings_in_the_schemas_order(): void
    {
        $channel = $this->channel('telegram', ['max_connections' => 12, 'allowed_updates' => ['message']]);

        $this->port()->report((string) $channel->getKey(), ['bot_username' => 'acme_bot']);

        $this->assertSame(
            ['allowed_updates' => ['message'], 'max_connections' => 12, 'bot_username' => 'acme_bot'],
            $channel->refresh()->config,
        );
        $this->assertSame(['allowed_updates', 'max_connections', 'bot_username'], array_keys($channel->config));
    }

    public function test_it_replaces_a_value_and_writes_into_an_empty_config(): void
    {
        $channel = $this->channel('telegram', []);
        $id      = (string) $channel->getKey();

        $this->port()->report($id, ['bot_username' => 'first_bot']);
        $this->assertSame(['bot_username' => 'first_bot'], $channel->refresh()->config);

        $this->port()->report($id, ['bot_username' => 'second_bot']);
        $this->assertSame(['bot_username' => 'second_bot'], $channel->refresh()->config);
    }

    public function test_a_null_removes_the_key(): void
    {
        $channel = $this->channel('telegram', ['max_connections' => 12, 'bot_username' => 'acme_bot']);

        $this->port()->report((string) $channel->getKey(), ['bot_username' => null]);

        $this->assertSame(['max_connections' => 12], $channel->refresh()->config);
    }

    public function test_a_key_the_schema_does_not_manage_is_refused_and_nothing_is_written(): void
    {
        $channel = $this->channel('telegram', ['max_connections' => 12]);

        foreach (['max_connections' => 20, 'no_such_key' => 'x'] as $key => $value) {
            try {
                $this->port()->report((string) $channel->getKey(), ['bot_username' => 'acme_bot', $key => $value]);
                $this->fail("The key {$key} was accepted.");
            } catch (InvalidManagedSettingException) {
                $this->assertSame(['max_connections' => 12], $channel->refresh()->config);
            }
        }
    }

    public function test_a_value_that_does_not_fit_the_field_is_refused(): void
    {
        $this->registerEcho();
        $channel = $this->channel('echo', ['retries' => 1]);

        $this->expectException(InvalidManagedSettingException::class);

        try {
            $this->port()->report((string) $channel->getKey(), ['account_name' => ['not', 'text']]);
        } finally {
            $this->assertSame(['retries' => 1], $channel->refresh()->config);
        }
    }

    public function test_a_value_is_stored_in_its_fields_type(): void
    {
        $this->registerEcho();
        $channel = $this->channel('echo', []);

        $this->port()->report((string) $channel->getKey(), ['account_id' => '12']);

        $this->assertSame(['account_id' => 12], $channel->refresh()->config);
    }

    public function test_a_type_without_a_profile_has_no_managed_key(): void
    {
        $this->app->make(ConnectorChannels::class)->add(
            new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoInboundAdapter::class),
            'echo',
        );
        $channel = $this->channel('echo', []);

        $this->expectException(InvalidManagedSettingException::class);

        $this->port()->report((string) $channel->getKey(), ['account_name' => 'acme']);
    }

    public function test_a_type_nothing_serves_has_no_managed_key(): void
    {
        $channel = $this->channel('viber', []);

        $this->expectException(InvalidManagedSettingException::class);

        $this->port()->report((string) $channel->getKey(), ['account_uri' => 'acme']);
    }

    public function test_a_channel_that_is_gone_is_ignored(): void
    {
        $this->port()->report('01a15dd8-d7c7-ea7c-137a-66006c89ad64', ['bot_username' => 'acme_bot']);

        $this->assertSame(0, Channel::query()->count());
    }

    public function test_it_does_not_synchronize_the_provider_again(): void
    {
        $channel = $this->channel('telegram', ['max_connections' => 40]);

        Bus::fake([SyncChannelWebhookJob::class]);

        $this->port()->report((string) $channel->getKey(), ['bot_username' => 'acme_bot']);

        Bus::assertNotDispatched(SyncChannelWebhookJob::class);
    }

    public function test_an_unchanged_value_is_not_written(): void
    {
        $channel = $this->channel('telegram', ['max_connections' => 40, 'bot_username' => 'acme_bot']);
        $updates = 0;

        DB::listen(static function ($query) use (&$updates): void {
            if (str_starts_with(mb_strtolower($query->sql), 'update')) {
                ++$updates;
            }
        });

        $this->port()->report((string) $channel->getKey(), ['bot_username' => 'acme_bot']);
        $this->assertSame(0, $updates);

        $this->port()->report((string) $channel->getKey(), ['bot_username' => 'other_bot']);
        $this->assertSame(1, $updates);
    }

    public function test_a_connector_registrar_reports_through_the_port_and_the_public_link_is_built_from_it(): void
    {
        $this->registerEcho();
        $channel = $this->channel('echo', ['retries' => 3]);

        $this->app->make(EchoWebhookRegistrar::class)->register(new WebhookRegistrationPayload(
            channelId: (string) $channel->getKey(),
            token: 'acme',
            secretToken: '',
            webhookPublicHash: 'hash',
        ));

        $channel->refresh();
        $link = $this->app->make(ChannelPublicLinks::class)->for($channel);

        $this->assertSame(['retries' => 3, 'account_name' => 'acme'], $channel->config);
        $this->assertSame('https://echo.example/acme', $link?->url);
        $this->assertSame('@acme', $link?->handle);
    }

    private function port(): ManagedChannelSettingsInterface
    {
        return $this->app->make(ManagedChannelSettingsInterface::class);
    }

    private function registerEcho(): void
    {
        $this->app->make(ConnectorChannels::class)->add(
            new ConnectorChannel(
                type: 'echo',
                sender: EchoSender::class,
                inbound: EchoInboundAdapter::class,
                webhookRegistrar: EchoWebhookRegistrar::class,
                profile: EchoChannelProfile::class,
            ),
            'echo',
            'Echo',
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function channel(string $type, array $config): Channel
    {
        return Channel::withoutEvents(fn (): Channel => Channel::factory()->create(['type' => $type, 'config' => $config]));
    }
}
