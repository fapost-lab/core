<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\ConnectorChannels;
use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Enums\ChannelWebhookStatus;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Services\ChannelPublicLinks;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Connector\ConnectorChannel;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Concerns\SetsRecordLimits;
use Tests\Feature\FeatureTestCase;
use Tests\Fixtures\Connectors\Echo\EchoChannelProfile;
use Tests\Fixtures\Connectors\Echo\EchoInboundAdapter;
use Tests\Fixtures\Connectors\Echo\EchoSender;

/**
 * Channels on the Inertia console: the assistant's list and the channel limit, creating and changing a channel by its
 * type, keeping the secrets out of every response, rotating the webhook hash, deleting, and what happens when the
 * provider refuses after the channel is stored.
 */
final class ChannelsConsoleTest extends FeatureTestCase
{
    use SetsRecordLimits;

    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string OTHER_TENANT_ID = '00000000-0000-0000-0000-0000000000ff';

    private const string TOKEN = 'bot-token-SECRET-111';

    private const string SECRET = 'webhook-secret-SECRET-222';

    private Assistant $assistant;

    /**
     * Writes to the webhook routing, recorded instead of sent to Redis.
     *
     * @var object{calls: list<array{0: string, 1: string}>}
     */
    private object $registry;

    private Dispatcher $realBus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        // Saving a channel outside a request (the factory) reaches the tenant context through the observer.
        $this->app->make(TenantContextInterface::class)->set($this->tenant());

        $this->registry = new class () implements ChannelWebhookRegistryInterface {
            /** @var list<array{0: string, 1: string}> */
            public array $calls = [];

            public function set(Channel $channel, TenantInterface $tenant): void
            {
                $this->calls[] = ['set', $channel->webhook_public_hash];
            }

            public function remove(string $webhookPublicHash): void
            {
                $this->calls[] = ['remove', $webhookPublicHash];
            }

            public function warmup(TenantInterface $tenant): void
            {
            }
        };
        $this->app->instance(ChannelWebhookRegistryInterface::class, $this->registry);
        // The channel observer is built once per model boot, so the fake has to be there before it is.
        Model::clearBootedModels();

        $this->realBus = Bus::getFacadeRoot();
        Bus::fake([SyncChannelWebhookJob::class]);

        $this->assistant = Assistant::factory()->create();
    }

    public function test_the_list_shows_the_channels_of_the_assistant_only(): void
    {
        $channel = $this->channel(['config' => ['bot_username' => 'help_bot']]);
        $this->channel(['is_active' => false, 'type' => ChannelTypeEnum::WhatsApp]);

        $this->channel(assistant: Assistant::factory()->create());
        $this->channel(['tenant_id' => self::OTHER_TENANT_ID], Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]));

        $base = "/assistant/{$this->assistant->getKey()}/channels";

        $this->actingAs($this->admin())
            ->get($this->listUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Channels/Index')
                ->where('table.meta.total', 2)
                ->where('table.state', ['search' => '', 'sort' => '-updated_at', 'perPage' => 25])
                ->where('table.defaults', ['sort' => '-updated_at', 'perPage' => 25, 'perPageOptions' => [25, 50, 100]])
                ->has('table.rows.1.id')
                ->where('table.rows', fn ($rows) => collect($rows)->contains(fn (array $row): bool => [
                    'id'                 => (string) $channel->getKey(),
                    'type'               => 'telegram',
                    'typeLabel'          => 'Telegram',
                    'available'          => true,
                    'handle'             => '@help_bot',
                    'url'                => 'https://t.me/help_bot',
                    'isActive'           => true,
                    'webhook'            => null,
                    'editUrl'            => "{$base}/{$channel->getKey()}/edit",
                    'deleteUrl'          => "{$base}/{$channel->getKey()}",
                    'rotateUrl'          => "{$base}/{$channel->getKey()}/rotate-webhook",
                    'registerWebhookUrl' => "{$base}/{$channel->getKey()}/register-webhook",
                ] === array_diff_key($row, ['updatedAt' => 1])))
                ->where('limit', ['reached' => false, 'hint' => null])
                ->where('can', ['create' => true, 'update' => true, 'delete' => true, 'rotate' => true])
                ->where('urls', ['index' => $base, 'create' => "{$base}/create"])
                ->etc());
    }

    public function test_a_channel_not_yet_registered_has_no_bot_link(): void
    {
        $this->channel();

        $this->actingAs($this->admin())
            ->get($this->listUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows.0.handle', null)
                ->where('table.rows.0.url', null)
                ->etc());
    }

    public function test_the_list_sorts_by_the_declared_columns_and_ignores_others(): void
    {
        $old = $this->channel(['type' => ChannelTypeEnum::WhatsApp]);
        $new = $this->channel();
        $old->forceFill(['updated_at' => now()->subDay()])->saveQuietly();

        $this->actingAs($this->admin());

        $this->get($this->listUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.rows.0.id', (string) $new->getKey())->etc());

        $this->get($this->listUrl('?sort=updated_at'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.rows.0.id', (string) $old->getKey())->etc());

        $this->get($this->listUrl('?sort=type'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.rows.0.type', 'telegram')->etc());

        $this->get($this->listUrl('?sort=-type'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.rows.0.type', 'whatsapp')->etc());

        $this->get($this->listUrl('?sort=token'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.state.sort', '-updated_at')->etc());
    }

    public function test_the_list_is_paged(): void
    {
        Channel::factory()->count(30)->create(['assistant_id' => $this->assistant->getKey()]);

        $this->actingAs($this->admin())
            ->get($this->listUrl('?page=2'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta', ['total' => 30, 'perPage' => 25, 'currentPage' => 2, 'lastPage' => 2, 'from' => 26, 'to' => 30])
                ->etc());
    }

    public function test_the_list_and_the_pages_never_carry_a_secret_or_the_webhook_hash(): void
    {
        $channel = $this->channel(['token' => self::TOKEN, 'secret_token' => self::SECRET]);
        $hash    = $channel->webhook_public_hash;

        $this->actingAs($this->admin());

        $list = $this->get($this->listUrl())->assertOk();
        $list->assertDontSee(self::TOKEN)->assertDontSee(self::SECRET)->assertDontSee($hash);
        $list->assertInertia(fn (AssertableInertia $page) => $page
            ->where('table.rows.0', fn ($row) => [] === array_intersect(['token', 'secret_token', 'webhookHash', 'webhook_public_hash'], array_keys($row->toArray())))
            ->etc());

        // The same through an Inertia visit, which answers with JSON.
        $this->get($this->listUrl(), $this->inertiaHeaders())->assertOk()
            ->assertDontSee(self::TOKEN)->assertDontSee(self::SECRET)->assertDontSee($hash);

        $edit = $this->get($this->listUrl("/{$channel->getKey()}/edit"))->assertOk();
        $edit->assertDontSee(self::TOKEN)->assertDontSee(self::SECRET);
        $edit->assertSee($hash);

        $this->get($this->listUrl('/create'))->assertOk()->assertDontSee(self::TOKEN);
    }

    public function test_at_the_limit_the_list_closes_creating_even_for_an_administrator(): void
    {
        $this->channel();
        $this->limitRecords('channels', 1);

        $this->actingAs($this->admin());

        $this->get($this->listUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('limit', ['reached' => true, 'hint' => 'Limit reached (1 of 1)'])
                ->where('can.create', false)
                ->etc());

        $this->get($this->listUrl('/create'))->assertForbidden();
    }

    public function test_the_limit_counts_the_whole_tenant_inactive_channels_included(): void
    {
        $this->channel(['is_active' => false], Assistant::factory()->create());
        $this->limitRecords('channels', 1);

        $this->actingAs($this->admin())
            ->get($this->listUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('limit.reached', true)->where('can.create', false)->etc());
    }

    public function test_below_the_limit_creating_is_open_and_offers_the_options(): void
    {
        $this->channel();
        $this->limitRecords('channels', 2);

        $this->actingAs($this->admin())
            ->get($this->listUrl('/create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Channels/Create')
                ->where('types', [
                    ['value' => 'telegram', 'label' => 'Telegram', 'usesSecretToken' => true],
                    ['value' => 'whatsapp', 'label' => 'WhatsApp', 'usesSecretToken' => true],
                ])
                ->where('settings.whatsapp', [])
                ->has('settings.telegram', 2)
                ->where('settings.telegram.0.key', 'allowed_updates')
                ->where('settings.telegram.0.type', 'choices')
                ->where('settings.telegram.0.label', 'Allowed updates')
                ->where('settings.telegram.0.default', [])
                ->has('settings.telegram.0.choices', 24)
                ->where('settings.telegram.0.choices.0', ['value' => 'message', 'label' => 'Message'])
                ->where('settings.telegram.1', [
                    'key'      => 'max_connections',
                    'type'     => 'integer',
                    'label'    => 'Max connections',
                    'help'     => 'Telegram webhook delivery parallelism, from 1 to 100. Defaults to 40.',
                    'required' => true,
                    'default'  => 40,
                    'min'      => 1,
                    'max'      => 100,
                    'choices'  => [],
                ])
                ->where('urls', ['index' => "/assistant/{$this->assistant->getKey()}/channels", 'submit' => "/assistant/{$this->assistant->getKey()}/channels"])
                ->etc());
    }

    public function test_a_telegram_channel_is_created_with_its_settings(): void
    {
        $this->actingAs($this->admin())
            ->post($this->listUrl(), $this->telegramPayload())
            ->assertRedirect("/assistant/{$this->assistant->getKey()}/channels")
            ->assertInertiaFlash('success', 'Channel created.');

        $channel = Channel::query()->sole();

        $this->assertSame(ChannelTypeEnum::Telegram->value, $channel->type);
        $this->assertSame(self::TENANT_ID, $channel->tenant_id);
        $this->assertSame((string) $this->assistant->getKey(), $channel->assistant_id);
        $this->assertSame(self::TOKEN, $channel->token);
        $this->assertSame(self::SECRET, $channel->secret_token);
        $this->assertTrue($channel->is_active);
        $this->assertEquals(['allowed_updates' => ['message', 'callback_query'], 'max_connections' => 25], $channel->config);
        $this->assertContains(['set', $channel->webhook_public_hash], $this->registry->calls);
        Bus::assertDispatchedSync(SyncChannelWebhookJob::class);
    }

    public function test_a_channel_whose_integration_declares_no_settings_keeps_no_config(): void
    {
        $this->actingAs($this->admin())
            ->post($this->listUrl(), [
                'type'         => 'whatsapp',
                'token'        => self::TOKEN,
                'secret_token' => 'any secret, even with spaces',
                'is_active'    => false,
                'config'       => ['phone_id' => '123', 'flag' => null],
            ])
            ->assertRedirect()
            ->assertInertiaFlash('success');

        $channel = Channel::query()->sole();

        $this->assertSame(ChannelTypeEnum::WhatsApp->value, $channel->type);
        $this->assertFalse($channel->is_active);
        $this->assertSame([], $channel->config);
        $this->assertSame([], $this->registry->calls);
    }

    public function test_creating_validates_the_fields(): void
    {
        $this->actingAs($this->admin());

        $this->post($this->listUrl(), [])->assertSessionHasErrors(['type', 'token', 'secret_token', 'is_active']);
        $this->post($this->listUrl(), [...$this->telegramPayload(), 'type' => 'carrier-pigeon'])->assertSessionHasErrors('type');
        $this->post($this->listUrl(), [...$this->telegramPayload(), 'secret_token' => 'bad!secret'])->assertSessionHasErrors('secret_token');
        $this->post($this->listUrl(), [...$this->telegramPayload(), 'secret_token' => str_repeat('a', 257)])->assertSessionHasErrors('secret_token');
        $this->post($this->listUrl(), [...$this->telegramPayload(), 'secret_token' => str_repeat('a', 256)])->assertSessionDoesntHaveErrors();

        $this->assertSame(1, Channel::query()->count());
    }

    public function test_the_telegram_settings_are_validated(): void
    {
        $this->actingAs($this->admin());

        $this->post($this->listUrl(), $this->telegramPayload(['config' => ['allowed_updates' => ['message', 'message'], 'max_connections' => 40]]))
            ->assertSessionHasErrors('config.allowed_updates.1');
        $this->post($this->listUrl(), $this->telegramPayload(['config' => ['allowed_updates' => ['not_an_update'], 'max_connections' => 40]]))
            ->assertSessionHasErrors('config.allowed_updates.0');
        $this->post($this->listUrl(), $this->telegramPayload(['config' => ['allowed_updates' => []]]))
            ->assertSessionHasErrors('config.max_connections');
        $this->post($this->listUrl(), $this->telegramPayload(['config' => ['allowed_updates' => [], 'max_connections' => 0]]))
            ->assertSessionHasErrors('config.max_connections');
        $this->post($this->listUrl(), $this->telegramPayload(['config' => ['allowed_updates' => [], 'max_connections' => 101]]))
            ->assertSessionHasErrors('config.max_connections');
        $this->post($this->listUrl(), $this->telegramPayload(['config' => ['max_connections' => 5]]))
            ->assertSessionHasErrors('config.allowed_updates');

        $this->assertSame(0, Channel::query()->count());
    }

    public function test_the_config_is_always_sent(): void
    {
        $this->actingAs($this->admin());

        $this->post($this->listUrl(), ['type' => 'whatsapp', 'token' => self::TOKEN, 'secret_token' => 'secret', 'is_active' => true])
            ->assertSessionHasErrors('config');
        $this->post($this->listUrl(), ['type' => 'whatsapp', 'token' => self::TOKEN, 'secret_token' => 'secret', 'is_active' => true, 'config' => 'text'])
            ->assertSessionHasErrors('config');

        $this->assertSame(0, Channel::query()->count());
    }

    public function test_a_create_that_lost_the_race_for_the_last_slot_is_refused_with_a_message(): void
    {
        $this->limitRecords('channels', 0);

        $this->actingAs($this->admin())
            ->post($this->listUrl(), $this->telegramPayload())
            ->assertRedirect()
            ->assertInertiaFlash('error', 'Channel limit reached. Channels limit reached: 0 of 0.');

        $this->assertSame(0, Channel::query()->count());
    }

    public function test_the_edit_screen_shows_the_channel_without_its_secrets(): void
    {
        $channel = $this->channel([
            'token'        => self::TOKEN,
            'secret_token' => self::SECRET,
            'config'       => ['allowed_updates' => ['message'], 'max_connections' => 12, 'bot_username' => 'help_bot'],
        ]);

        $this->actingAs($this->admin())
            ->get($this->listUrl("/{$channel->getKey()}/edit"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Channels/Edit')
                ->where('channel', [
                    'id'                 => (string) $channel->getKey(),
                    'type'               => 'telegram',
                    'typeLabel'          => 'Telegram',
                    'available'          => true,
                    'isActive'           => true,
                    'webhook'            => null,
                    'webhookAt'          => null,
                    'registerWebhookUrl' => "/assistant/{$this->assistant->getKey()}/channels/{$channel->getKey()}/register-webhook",
                    'webhookHash'        => $channel->webhook_public_hash,
                    'handle'             => '@help_bot',
                    'url'                => 'https://t.me/help_bot',
                    'settings'           => ['allowed_updates' => ['message'], 'max_connections' => 12],
                ])
                ->where('urls.submit', "/assistant/{$this->assistant->getKey()}/channels/{$channel->getKey()}")
                ->etc());
    }

    public function test_the_edit_screen_defaults_a_setting_that_is_not_stored_and_lists_none_for_a_type_without_settings(): void
    {
        $telegram = $this->channel(['config' => []]);
        $other    = $this->channel(['type' => ChannelTypeEnum::WhatsApp, 'config' => ['phone_id' => '123']]);

        $this->actingAs($this->admin());

        $this->get($this->listUrl("/{$telegram->getKey()}/edit"))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('channel.settings', ['allowed_updates' => [], 'max_connections' => 40])->etc());

        $this->get($this->listUrl("/{$other->getKey()}/edit"))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('channel.settings', [])->etc());
    }

    public function test_empty_secrets_keep_the_stored_ones_and_filled_ones_replace_them(): void
    {
        $channel = $this->channel(['token' => self::TOKEN, 'secret_token' => self::SECRET]);

        $this->actingAs($this->admin());

        $this->put($this->listUrl("/{$channel->getKey()}"), $this->telegramPayload(['token' => '', 'secret_token' => null]))
            ->assertRedirect("/assistant/{$this->assistant->getKey()}/channels")
            ->assertInertiaFlash('success', 'Channel saved.');

        $channel->refresh();
        $this->assertSame(self::TOKEN, $channel->token);
        $this->assertSame(self::SECRET, $channel->secret_token);

        $this->put($this->listUrl("/{$channel->getKey()}"), $this->telegramPayload(['token' => 'new-token', 'secret_token' => 'new_secret']))
            ->assertRedirect();

        $channel->refresh();
        $this->assertSame('new-token', $channel->token);
        $this->assertSame('new_secret', $channel->secret_token);
    }

    public function test_a_change_keeps_the_type_whatever_the_request_says(): void
    {
        $channel = $this->channel(['config' => ['allowed_updates' => ['poll'], 'max_connections' => 10, 'bot_username' => 'help_bot']]);

        $this->actingAs($this->admin())
            ->put($this->listUrl("/{$channel->getKey()}"), $this->telegramPayload(['type' => 'whatsapp', 'is_active' => false]))
            ->assertRedirect();

        $channel->refresh();
        $this->assertSame(ChannelTypeEnum::Telegram->value, $channel->type);
        $this->assertFalse($channel->is_active);
        // The settings are replaced by what the form sent; the bot name is the provider's, so it stays.
        $this->assertSame(['allowed_updates' => ['message', 'callback_query'], 'max_connections' => 25, 'bot_username' => 'help_bot'], $channel->config);
    }

    public function test_a_managed_setting_is_not_taken_from_the_request(): void
    {
        $channel = $this->channel(['config' => ['max_connections' => 10, 'bot_username' => 'help_bot']]);

        $this->actingAs($this->admin())
            ->put($this->listUrl("/{$channel->getKey()}"), $this->telegramPayload(['config' => ['allowed_updates' => [], 'max_connections' => 11, 'bot_username' => 'evil_bot']]))
            ->assertRedirect();

        $this->assertSame('help_bot', $channel->refresh()->config['bot_username']);
        $this->assertSame(11, $channel->config['max_connections']);
    }

    public function test_the_settings_of_a_changed_channel_follow_its_stored_type(): void
    {
        $channel = $this->channel(['type' => ChannelTypeEnum::WhatsApp, 'config' => ['old' => 'x']]);

        $this->actingAs($this->admin());

        // Telegram's settings are no rules for a channel of another type, and are not kept.
        $this->put($this->listUrl("/{$channel->getKey()}"), $this->telegramPayload())->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->assertSame([], $channel->refresh()->config);

        $this->put($this->listUrl("/{$channel->getKey()}"), ['is_active' => true])->assertSessionHasErrors('config');
    }

    public function test_a_change_validates_the_secret_format_for_telegram(): void
    {
        $channel = $this->channel();

        $this->actingAs($this->admin())
            ->put($this->listUrl("/{$channel->getKey()}"), $this->telegramPayload(['secret_token' => 'bad secret']))
            ->assertSessionHasErrors('secret_token');
    }

    public function test_rotating_gives_a_new_hash_and_moves_the_routing(): void
    {
        $channel = $this->channel();
        $old     = $channel->webhook_public_hash;

        $response = $this->actingAs($this->admin())
            ->from($this->listUrl('?sort=type'))
            ->post($this->listUrl("/{$channel->getKey()}/rotate-webhook"))
            ->assertRedirect($this->listUrl('?sort=type'));

        $new = $channel->refresh()->webhook_public_hash;

        $this->assertNotSame($old, $new);
        $this->assertSame([['remove', $old], ['set', $new]], array_slice($this->registry->calls, -2));
        // The new hash is told once, to the one who may rotate.
        $response->assertInertiaFlash('success', "Webhook hash rotated. New hash: {$new}");
    }

    public function test_rotating_needs_the_rotation_permission(): void
    {
        $channel = $this->channel();
        $hash    = $channel->webhook_public_hash;

        $this->actingAs($this->userWith(Permission::ManageChannels));

        $this->get($this->listUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can', ['create' => true, 'update' => true, 'delete' => true, 'rotate' => false])
                ->etc());

        $this->post($this->listUrl("/{$channel->getKey()}/rotate-webhook"))->assertForbidden();
        $this->assertSame($hash, $channel->refresh()->webhook_public_hash);

        $this->actingAs($this->userWith(Permission::ManageChannels, Permission::RotateChannelToken));

        $this->get($this->listUrl())->assertInertia(fn (AssertableInertia $page) => $page->where('can.rotate', true)->etc());
        $this->post($this->listUrl("/{$channel->getKey()}/rotate-webhook"))->assertRedirect();
        $this->assertNotSame($hash, $channel->refresh()->webhook_public_hash);
    }

    public function test_a_type_that_is_not_a_string_is_a_validation_error(): void
    {
        $this->actingAs($this->admin())
            ->post($this->listUrl(), [...$this->telegramPayload(), 'type' => ['telegram']])
            ->assertSessionHasErrors('type');

        $this->assertSame(0, Channel::query()->count());
    }

    public function test_the_edit_screen_offers_only_update_types_the_form_knows(): void
    {
        $channel = $this->channel(['config' => ['allowed_updates' => ['message', 'retired_update', 7], 'max_connections' => 12]]);

        $this->actingAs($this->admin())
            ->get($this->listUrl("/{$channel->getKey()}/edit"))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('channel.settings.allowed_updates', ['message'])->etc());
    }

    public function test_rotating_also_needs_to_see_channels(): void
    {
        $channel = $this->channel();
        $hash    = $channel->webhook_public_hash;

        // May rotate, may not list: the new hash would be shown to someone who cannot see the channel.
        $this->actingAs($this->userWith(Permission::RotateChannelToken));

        $this->post($this->listUrl("/{$channel->getKey()}/rotate-webhook"))->assertForbidden();
        $this->assertSame($hash, $channel->refresh()->webhook_public_hash);
    }

    public function test_a_refusal_while_switching_a_channel_off_has_its_own_message(): void
    {
        $channel = $this->channel(['token' => self::TOKEN]);

        $this->refuseTheProvider();

        $this->actingAs($this->admin())
            ->put($this->listUrl("/{$channel->getKey()}"), $this->telegramPayload(['is_active' => false]))
            ->assertRedirect()
            ->assertInertiaFlash('error', 'The channel is saved as inactive, but Telegram did not confirm removing its webhook.');

        $this->assertFalse($channel->refresh()->is_active);
    }

    public function test_a_channel_is_deleted_and_its_routing_removed(): void
    {
        $channel = $this->channel();
        $hash    = $channel->webhook_public_hash;

        $this->actingAs($this->admin())
            ->from($this->listUrl('?page=1'))
            ->delete($this->listUrl("/{$channel->getKey()}"))
            ->assertRedirect($this->listUrl('?page=1'))
            ->assertInertiaFlash('success', 'Channel deleted.');

        $this->assertModelMissing($channel);
        $this->assertContains(['remove', $hash], $this->registry->calls);
        Bus::assertDispatchedSync(SyncChannelWebhookJob::class, fn (SyncChannelWebhookJob $job): bool => false === $job->register);
    }

    public function test_a_save_that_runs_the_provider_job_in_the_request_leaves_the_assistant_of_the_request(): void
    {
        // The job switches tenants inside the request: the assistant resolved for the request is still there after.
        Bus::swap($this->realBus);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'help_bot']])]);

        $this->actingAs($this->admin())
            ->post($this->listUrl(), $this->telegramPayload())
            ->assertRedirect("/assistant/{$this->assistant->getKey()}/channels")
            ->assertInertiaFlash('success', 'Channel created.');

        $channel = Channel::query()->sole();

        $this->assertSame('help_bot', $channel->config['bot_username']);
        $this->assertSame(25, $channel->config['max_connections']);
        $this->assertSame(ChannelWebhookStatus::Registered, $channel->webhook_status);
        $this->assertNotNull($channel->webhook_status_at);

        $this->put($this->listUrl("/{$channel->getKey()}"), $this->telegramPayload(['is_active' => false]))->assertRedirect()->assertInertiaFlash('success');
        $this->assertNull($channel->refresh()->webhook_status);
        $this->post($this->listUrl("/{$channel->getKey()}/rotate-webhook"))->assertRedirect()->assertInertiaFlash('success');
        $this->delete($this->listUrl("/{$channel->getKey()}"))->assertRedirect()->assertInertiaFlash('success', 'Channel deleted.');
    }

    public function test_a_provider_that_refuses_after_the_channel_is_stored_is_worded_without_the_exception(): void
    {
        $this->refuseTheProvider();

        $response = $this->actingAs($this->admin())
            ->post($this->listUrl(), $this->telegramPayload())
            ->assertRedirect("/assistant/{$this->assistant->getKey()}/channels")
            ->assertInertiaFlash('error', 'The channel is saved, but the webhook is not registered. Check the token and register it again from the list.');

        // The record is there for the user to correct, flagged, and nothing of the request, the token among it, is shown.
        $channel = Channel::query()->sole();

        $this->assertSame(ChannelWebhookStatus::Failed, $channel->webhook_status);
        $this->assertNotNull($channel->webhook_status_at);
        $this->assertStringNotContainsString(self::TOKEN, (string) $response->getContent());
        $this->assertStringNotContainsString(self::TOKEN, json_encode(session()->all(), JSON_THROW_ON_ERROR));
    }

    public function test_a_refusal_while_saving_changes_is_a_toast_and_flags_the_channel(): void
    {
        $channel = $this->channel(['token' => self::TOKEN]);

        $this->refuseTheProvider();

        $this->actingAs($this->admin())
            ->put($this->listUrl("/{$channel->getKey()}"), $this->telegramPayload(['token' => 'bot-token-NEW-333']))
            ->assertRedirect()
            ->assertInertiaFlash('error', 'The channel is saved, but the webhook is not registered. Check the token and register it again from the list.');

        $this->assertSame(ChannelWebhookStatus::Failed, $channel->refresh()->webhook_status);
    }

    public function test_a_refusal_while_rotating_or_deleting_is_a_toast_not_a_server_error(): void
    {
        $channel = $this->channel(['token' => self::TOKEN]);
        $old     = $channel->webhook_public_hash;

        $this->refuseTheProvider();

        $this->actingAs($this->admin());

        $this->post($this->listUrl("/{$channel->getKey()}/rotate-webhook"))
            ->assertRedirect()
            ->assertInertiaFlash('error', 'The webhook hash is changed, but the webhook is not registered. Check the token and register it again.');

        // The rotation stands: the leaked hash is revoked, the new route is written and the channel is flagged.
        $channel->refresh();
        $this->assertNotSame($old, $channel->webhook_public_hash);
        $this->assertSame(ChannelWebhookStatus::Failed, $channel->webhook_status);
        $this->assertSame([['remove', $old], ['set', $channel->webhook_public_hash]], array_slice($this->registry->calls, -2));

        $this->delete($this->listUrl("/{$channel->getKey()}"))
            ->assertRedirect()
            ->assertInertiaFlash('error', 'The channel is deleted, but Telegram did not confirm removing its webhook.');

        $this->assertModelMissing($channel);
    }

    public function test_the_list_flags_a_channel_whose_webhook_is_refused_without_a_secret(): void
    {
        $failed = $this->channel(['token' => self::TOKEN, 'webhook_status' => ChannelWebhookStatus::Failed->value, 'webhook_status_at' => now()]);
        $this->channel(['webhook_status' => ChannelWebhookStatus::Registered->value]);
        $this->channel(['is_active' => false, 'webhook_status' => ChannelWebhookStatus::Failed->value]);

        $this->actingAs($this->admin())
            ->get($this->listUrl())
            ->assertOk()
            ->assertDontSee(self::TOKEN)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows', fn ($rows) => 1 === collect($rows)->where('webhook', 'failed')->count()
                    && (string) $failed->getKey() === collect($rows)->firstWhere('webhook', 'failed')['id'])
                ->etc());

        $this->get($this->listUrl("/{$failed->getKey()}/edit"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('channel.webhook', 'failed')
                ->whereType('channel.webhookAt', 'string')
                ->etc());
    }

    public function test_registering_again_stores_the_outcome(): void
    {
        $channel = $this->channel(['token' => self::TOKEN, 'webhook_status' => ChannelWebhookStatus::Failed->value]);

        Bus::swap($this->realBus);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'help_bot']])]);

        $this->actingAs($this->admin())
            ->from($this->listUrl('?sort=type'))
            ->post($this->listUrl("/{$channel->getKey()}/register-webhook"))
            ->assertRedirect($this->listUrl('?sort=type'))
            ->assertInertiaFlash('success', 'Webhook registered.');

        $this->assertSame(ChannelWebhookStatus::Registered, $channel->refresh()->webhook_status);
    }

    public function test_registering_again_that_is_refused_stays_flagged(): void
    {
        $channel = $this->channel(['token' => self::TOKEN, 'webhook_status' => ChannelWebhookStatus::Failed->value]);

        $this->refuseTheProvider();

        $response = $this->actingAs($this->admin())
            ->post($this->listUrl("/{$channel->getKey()}/register-webhook"))
            ->assertRedirect()
            ->assertInertiaFlash('error', 'The webhook was not registered. Check the token and try again.');

        $this->assertSame(ChannelWebhookStatus::Failed, $channel->refresh()->webhook_status);
        $this->assertStringNotContainsString(self::TOKEN, (string) $response->getContent());
    }

    public function test_registering_again_is_not_offered_for_an_inactive_channel(): void
    {
        $channel = $this->channel(['is_active' => false]);

        $this->actingAs($this->admin())
            ->post($this->listUrl("/{$channel->getKey()}/register-webhook"))
            ->assertRedirect()
            ->assertInertiaFlash('error', 'Only an active channel has a webhook to register.');

        Bus::assertNotDispatchedSync(SyncChannelWebhookJob::class);
    }

    public function test_registering_again_needs_the_update_permission(): void
    {
        $channel = $this->channel(['webhook_status' => ChannelWebhookStatus::Failed->value]);

        $this->actingAs($this->userWith(Permission::RotateChannelToken));

        $this->post($this->listUrl("/{$channel->getKey()}/register-webhook"))->assertForbidden();
        $this->assertSame(ChannelWebhookStatus::Failed, $channel->refresh()->webhook_status);
    }

    public function test_a_channel_of_another_assistant_or_tenant_or_a_bad_id_is_not_found(): void
    {
        $other   = $this->channel(assistant: Assistant::factory()->create());
        $foreign = $this->channel(['tenant_id' => self::OTHER_TENANT_ID], Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]));

        $this->actingAs($this->admin());

        foreach ([$other, $foreign] as $channel) {
            $this->get($this->listUrl("/{$channel->getKey()}/edit"))->assertNotFound();
            $this->put($this->listUrl("/{$channel->getKey()}"), $this->telegramPayload())->assertNotFound();
            $this->post($this->listUrl("/{$channel->getKey()}/rotate-webhook"))->assertNotFound();
            $this->delete($this->listUrl("/{$channel->getKey()}"))->assertNotFound();
        }

        $this->get($this->listUrl('/not-an-id/edit'))->assertNotFound();
        $this->post($this->listUrl('/not-an-id/rotate-webhook'))->assertNotFound();
        $this->assertSame(2, Channel::query()->count());
    }

    public function test_a_user_without_the_channel_permission_is_refused_everywhere(): void
    {
        $channel = $this->channel();

        // Managing assistants opens the console but gives no channel access.
        $this->actingAs($this->userWith(Permission::ManageFlowDefinitions));

        $this->get($this->listUrl())->assertForbidden();
        $this->get($this->listUrl('/create'))->assertForbidden();
        $this->post($this->listUrl(), $this->telegramPayload())->assertForbidden();
        $this->get($this->listUrl("/{$channel->getKey()}/edit"))->assertForbidden();
        $this->put($this->listUrl("/{$channel->getKey()}"), $this->telegramPayload())->assertForbidden();
        $this->post($this->listUrl("/{$channel->getKey()}/rotate-webhook"))->assertForbidden();
        $this->delete($this->listUrl("/{$channel->getKey()}"))->assertForbidden();

        $this->assertSame(1, Channel::query()->count());
    }

    public function test_a_manager_cannot_reach_the_channels_of_an_assistant_they_are_not_assigned_to(): void
    {
        $unassigned = Assistant::factory()->create();
        $channel    = $this->channel(assistant: $unassigned);

        $this->actingAs($this->userWith(Permission::ManageChannels, Permission::RotateChannelToken));

        $this->get($this->panelUrl("/assistant/{$unassigned->getKey()}/channels"))->assertNotFound();
        $this->delete($this->panelUrl("/assistant/{$unassigned->getKey()}/channels/{$channel->getKey()}"))->assertNotFound();
        $this->assertModelExists($channel);
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->get($this->listUrl())->assertRedirect(route('console.auth.login'));
    }

    public function test_a_connector_channel_type_is_offered_and_a_channel_of_it_is_created(): void
    {
        $this->registerEcho();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get($this->listUrl('/create'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('types', [
                    ['value' => 'telegram', 'label' => 'Telegram', 'usesSecretToken' => true],
                    ['value' => 'whatsapp', 'label' => 'WhatsApp', 'usesSecretToken' => true],
                    ['value' => 'echo', 'label' => 'Echo', 'usesSecretToken' => true],
                ])
                ->where('settings.echo', [])
                ->etc());

        $this->post($this->listUrl(), [
            'type'         => 'echo',
            'token'        => self::TOKEN,
            'secret_token' => 'echo secret',
            'is_active'    => true,
            'config'       => ['region' => 'eu'],
        ])
            ->assertRedirect("/assistant/{$this->assistant->getKey()}/channels")
            ->assertInertiaFlash('success', 'Channel created.');

        $channel = Channel::query()->sole();

        $this->assertSame('echo', $channel->type);
        // The integration declared no profile, so it has no settings to keep, and no link.
        $this->assertSame([], $channel->config);
        $this->assertNull($this->app->make(ChannelPublicLinks::class)->for($channel));

        $this->get($this->listUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows.0.type', 'echo')
                ->where('table.rows.0.typeLabel', 'Echo')
                ->where('table.rows.0.available', true)
                ->etc());
    }

    public function test_a_connector_profile_offers_its_settings_and_no_secret_token(): void
    {
        $this->registerEcho(profile: true);

        $this->actingAs($this->admin())
            ->get($this->listUrl('/create'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('types.2', ['value' => 'echo', 'label' => 'Echo', 'usesSecretToken' => false])
                ->where('settings.echo', fn ($fields) => ['region', 'retries', 'sandbox', 'mode', 'events'] === collect($fields)->pluck('key')->all()
                    && ['text', 'integer', 'boolean', 'choice', 'choices'] === collect($fields)->pluck('type')->all())
                ->where('settings.echo.0.help', 'Where the account lives.')
                ->where('settings.echo.3.choices', [['value' => 'fast', 'label' => 'Fast'], ['value' => 'safe', 'label' => 'Safe']])
                ->where('settings.echo.4.default', ['sent'])
                ->etc());
    }

    public function test_a_connector_channel_is_created_with_typed_settings_and_without_a_secret_token(): void
    {
        $this->registerEcho(profile: true);

        $this->actingAs($this->admin())
            ->post($this->listUrl(), $this->echoPayload(['config' => [
                'events'       => ['read', 'sent'],
                'mode'         => 'fast',
                'sandbox'      => true,
                'retries'      => '3',
                'region'       => 'us',
                'account_name' => 'forged',
                'unknown'      => 'x',
            ]]))
            ->assertRedirect("/assistant/{$this->assistant->getKey()}/channels")
            ->assertInertiaFlash('success', 'Channel created.');

        $channel = Channel::query()->sole();

        // Typed, in the order of the schema, only what it declares and the form may write.
        $this->assertSame(['region' => 'us', 'retries' => 3, 'sandbox' => true, 'mode' => 'fast', 'events' => ['read', 'sent']], $channel->config);
        $this->assertSame('', $channel->secret_token);
    }

    public function test_a_connector_settings_are_validated_by_their_type(): void
    {
        $this->registerEcho(profile: true);
        $this->actingAs($this->admin());

        $valid = ['region' => 'eu', 'retries' => 1, 'sandbox' => false, 'mode' => 'safe', 'events' => ['sent']];

        foreach ([
            'region'  => [str_repeat('x', 9), 'config.region'],
            'retries' => [6, 'config.retries'],
            'sandbox' => ['maybe', 'config.sandbox'],
            'mode'    => ['turbo', 'config.mode'],
            'events'  => [['sent', 'sent'], 'config.events.1'],
        ] as $key => [$value, $error]) {
            $this->post($this->listUrl(), $this->echoPayload(['config' => [...$valid, $key => $value]]))->assertSessionHasErrors($error);
        }

        // A required setting must be given: a number, a choice, and at least one of a list.
        $this->post($this->listUrl(), $this->echoPayload(['config' => [...$valid, 'retries' => null]]))->assertSessionHasErrors('config.retries');
        $this->post($this->listUrl(), $this->echoPayload(['config' => [...$valid, 'mode' => null]]))->assertSessionHasErrors('config.mode');
        $this->post($this->listUrl(), $this->echoPayload(['config' => [...$valid, 'events' => []]]))->assertSessionHasErrors('config.events');
        // An optional one may be left out.
        $this->post($this->listUrl(), $this->echoPayload(['config' => ['retries' => 0, 'mode' => 'safe', 'events' => ['read']]]))->assertSessionDoesntHaveErrors();

        // The errors name the setting by its label.
        $this->post($this->listUrl(), $this->echoPayload(['config' => [...$valid, 'retries' => 6]]))
            ->assertSessionHasErrors(['config.retries' => 'The Retries field must not be greater than 5.']);

        $this->assertSame(1, Channel::query()->count());
    }

    public function test_saving_the_form_keeps_what_the_integration_wrote_and_the_link_shows(): void
    {
        $this->registerEcho(profile: true);
        $channel = $this->channel(['type' => 'echo', 'config' => ['retries' => 1, 'mode' => 'safe', 'events' => ['sent'], 'account_name' => 'acme']]);

        $this->actingAs($this->admin());

        $this->get($this->listUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows.0.handle', '@acme')
                ->where('table.rows.0.url', 'https://echo.example/acme')
                ->etc());

        $this->get($this->listUrl("/{$channel->getKey()}/edit"))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('channel.handle', '@acme')
                // Typed values, defaults for what is not stored, and nothing of the managed setting.
                ->where('channel.settings', ['region' => 'eu', 'retries' => 1, 'sandbox' => false, 'mode' => 'safe', 'events' => ['sent']])
                ->etc());

        $this->put($this->listUrl("/{$channel->getKey()}"), $this->echoPayload(['type' => null, 'config' => ['retries' => 4, 'mode' => 'fast', 'events' => ['failed']]]))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(['retries' => 4, 'mode' => 'fast', 'events' => ['failed'], 'account_name' => 'acme'], $channel->refresh()->config);
    }

    public function test_the_edit_screen_offers_only_choices_the_integration_declared(): void
    {
        $this->registerEcho(profile: true);
        $channel = $this->channel(['type' => 'echo', 'config' => ['retries' => '2', 'mode' => 'retired', 'events' => ['read', 'gone', 9]]]);

        $this->actingAs($this->admin())
            ->get($this->listUrl("/{$channel->getKey()}/edit"))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('channel.settings', ['region' => 'eu', 'retries' => 2, 'sandbox' => false, 'mode' => 'safe', 'events' => ['read']])
                ->etc());
    }

    public function test_a_type_nothing_serves_is_not_accepted_when_creating(): void
    {
        $this->actingAs($this->admin())
            ->post($this->listUrl(), [
                'type'         => 'echo',
                'token'        => self::TOKEN,
                'secret_token' => 'echo secret',
                'is_active'    => true,
                'config'       => [],
            ])
            ->assertSessionHasErrors('type');

        $this->assertSame(0, Channel::query()->count());
    }

    public function test_a_channel_whose_type_lost_its_integration_is_listed_as_unavailable_and_can_only_be_deleted(): void
    {
        $channel = $this->channel(['type' => 'viber', 'config' => ['a' => 'b']]);

        $this->actingAs($this->admin())
            ->get($this->listUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows.0.type', 'viber')
                ->where('table.rows.0.typeLabel', 'viber')
                ->where('table.rows.0.available', false)
                ->where('table.rows.0.handle', null)
                ->etc());

        $this->get($this->listUrl("/{$channel->getKey()}/edit"))->assertStatus(409);
        $this->put($this->listUrl("/{$channel->getKey()}"), ['is_active' => false, 'config' => []])->assertStatus(409);
        $this->assertTrue($channel->refresh()->is_active);

        $hash = $channel->webhook_public_hash;
        $this->post($this->listUrl("/{$channel->getKey()}/rotate-webhook"))->assertStatus(409);
        $this->post($this->listUrl("/{$channel->getKey()}/register-webhook"))->assertStatus(409);
        $this->assertSame($hash, $channel->refresh()->webhook_public_hash);

        $this->delete($this->listUrl("/{$channel->getKey()}"))->assertRedirect();

        $this->assertSame(0, Channel::query()->count());
    }

    /**
     * The real bus, and Telegram answering every call with a refusal.
     */
    private function refuseTheProvider(): void
    {
        Bus::swap($this->realBus);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function channel(array $attributes = [], ?Assistant $assistant = null): Channel
    {
        return Channel::factory()->create([
            'assistant_id' => ($assistant ?? $this->assistant)->getKey(),
            ...$attributes,
        ]);
    }

    private function registerEcho(bool $profile = false): void
    {
        $this->app->make(ConnectorChannels::class)->add(
            new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoInboundAdapter::class, profile: $profile ? EchoChannelProfile::class : null),
            'echo',
            'Echo',
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, mixed>
     */
    private function telegramPayload(array $overrides = []): array
    {
        return [
            'type'         => 'telegram',
            'token'        => self::TOKEN,
            'secret_token' => self::SECRET,
            'is_active'    => true,
            'config'       => ['allowed_updates' => ['message', 'callback_query'], 'max_connections' => 25],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, mixed>
     */
    private function echoPayload(array $overrides = []): array
    {
        return array_filter([
            'type'      => 'echo',
            'token'     => self::TOKEN,
            'is_active' => true,
            'config'    => ['retries' => 2, 'mode' => 'safe', 'events' => ['sent']],
            ...$overrides,
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * @return array<string, string>
     */
    private function inertiaHeaders(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) Inertia::getVersion()];
    }

    private function listUrl(string $suffix = ''): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/channels{$suffix}");
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    private function userWith(Permission ...$permissions): User
    {
        $user = User::factory()->create();
        // Opening an assistant's console needs ManageAssistants; the channel permissions are what the screen asks on top.
        $user->givePermissionTo([Permission::ManageAssistants->value, ...array_map(static fn (Permission $permission): string => $permission->value, $permissions)]);
        $user->assistants()->attach($this->assistant);

        return $user;
    }
}
