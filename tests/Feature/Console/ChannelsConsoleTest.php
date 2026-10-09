<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Concerns\SetsRecordLimits;

/**
 * Channels on the Inertia console: the assistant's list and the channel limit, creating and changing a channel by its
 * type, keeping the secrets out of every response, rotating the webhook hash, deleting, and what happens when the
 * provider refuses after the channel is stored.
 */
final class ChannelsConsoleTest extends InertiaConsoleTestCase
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
        $channel = $this->channel(['telegram_bot_username' => 'help_bot']);
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
                    'id'        => (string) $channel->getKey(),
                    'type'      => 'telegram',
                    'typeLabel' => 'Telegram',
                    'handle'    => '@help_bot',
                    'url'       => 'https://t.me/help_bot',
                    'isActive'  => true,
                    'editUrl'   => "{$base}/{$channel->getKey()}/edit",
                    'deleteUrl' => "{$base}/{$channel->getKey()}",
                    'rotateUrl' => "{$base}/{$channel->getKey()}/rotate-webhook",
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
                ->where('types', [['value' => 'telegram', 'label' => 'Telegram'], ['value' => 'whatsapp', 'label' => 'WhatsApp']])
                ->where('telegramUpdates.0', ['value' => 'message', 'label' => 'Message'])
                ->has('telegramUpdates', 24)
                ->where('maxConnections', ['min' => 1, 'max' => 100, 'default' => 40])
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

        $this->assertSame(ChannelTypeEnum::Telegram, $channel->type);
        $this->assertSame(self::TENANT_ID, $channel->tenant_id);
        $this->assertSame((string) $this->assistant->getKey(), $channel->assistant_id);
        $this->assertSame(self::TOKEN, $channel->token);
        $this->assertSame(self::SECRET, $channel->secret_token);
        $this->assertTrue($channel->is_active);
        $this->assertEquals(['allowed_updates' => ['message', 'callback_query'], 'max_connections' => 25], $channel->config);
        $this->assertContains(['set', $channel->webhook_public_hash], $this->registry->calls);
        Bus::assertDispatchedSync(SyncChannelWebhookJob::class);
    }

    public function test_a_channel_without_named_settings_takes_its_pairs_as_the_config(): void
    {
        $this->actingAs($this->admin())
            ->post($this->listUrl(), [
                'type'           => 'whatsapp',
                'token'          => self::TOKEN,
                'secret_token'   => 'any secret, even with spaces',
                'is_active'      => false,
                'config_entries' => [['key' => 'phone_id', 'value' => '123'], ['key' => 'flag', 'value' => null]],
            ])
            ->assertRedirect()
            ->assertInertiaFlash('success');

        $channel = Channel::query()->sole();

        $this->assertSame(ChannelTypeEnum::WhatsApp, $channel->type);
        $this->assertFalse($channel->is_active);
        $this->assertEquals(['phone_id' => '123', 'flag' => ''], $channel->config);
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

    public function test_the_key_value_settings_are_validated(): void
    {
        $this->actingAs($this->admin());
        $payload = ['type' => 'whatsapp', 'token' => self::TOKEN, 'secret_token' => 'secret', 'is_active' => true];

        $this->post($this->listUrl(), [...$payload, 'config_entries' => [['key' => 'a', 'value' => '1'], ['key' => 'a', 'value' => '2']]])
            ->assertSessionHasErrors('config_entries.1.key');
        $this->post($this->listUrl(), [...$payload, 'config_entries' => [['key' => '', 'value' => '1']]])
            ->assertSessionHasErrors('config_entries.0.key');
        $this->post($this->listUrl(), [...$payload, 'config_entries' => array_map(
            static fn (int $i): array => ['key' => "key{$i}", 'value' => 'x'],
            range(1, 51),
        )])->assertSessionHasErrors('config_entries');
        $this->post($this->listUrl(), $payload)->assertSessionHasErrors('config_entries');

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
            'token'                 => self::TOKEN,
            'secret_token'          => self::SECRET,
            'telegram_bot_username' => 'help_bot',
            'config'                => ['allowed_updates' => ['message'], 'max_connections' => 12],
        ]);

        $this->actingAs($this->admin())
            ->get($this->listUrl("/{$channel->getKey()}/edit"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Channels/Edit')
                ->where('channel', [
                    'id'            => (string) $channel->getKey(),
                    'type'          => 'telegram',
                    'typeLabel'     => 'Telegram',
                    'isActive'      => true,
                    'webhookHash'   => $channel->webhook_public_hash,
                    'handle'        => '@help_bot',
                    'url'           => 'https://t.me/help_bot',
                    'telegram'      => ['allowedUpdates' => ['message'], 'maxConnections' => 12],
                    'configEntries' => null,
                ])
                ->where('urls.submit', "/assistant/{$this->assistant->getKey()}/channels/{$channel->getKey()}")
                ->etc());
    }

    public function test_the_edit_screen_defaults_the_telegram_connections_and_lists_scalar_pairs_of_other_types(): void
    {
        $telegram = $this->channel(['config' => []]);
        $other    = $this->channel(['type' => ChannelTypeEnum::WhatsApp, 'config' => ['phone_id' => '123', 'retries' => 3, 'nested' => ['a' => 1]]]);

        $this->actingAs($this->admin());

        $this->get($this->listUrl("/{$telegram->getKey()}/edit"))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('channel.telegram', ['allowedUpdates' => [], 'maxConnections' => 40])->etc());

        $this->get($this->listUrl("/{$other->getKey()}/edit"))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('channel.telegram', null)
                ->where('channel.configEntries', [['key' => 'phone_id', 'value' => '123'], ['key' => 'retries', 'value' => '3']])
                ->etc());
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
        $channel = $this->channel(['config' => ['allowed_updates' => ['poll'], 'max_connections' => 10]]);

        $this->actingAs($this->admin())
            ->put($this->listUrl("/{$channel->getKey()}"), $this->telegramPayload(['type' => 'whatsapp', 'is_active' => false]))
            ->assertRedirect();

        $channel->refresh();
        $this->assertSame(ChannelTypeEnum::Telegram, $channel->type);
        $this->assertFalse($channel->is_active);
        // The whole config is replaced by what the form sent.
        $this->assertEquals(['allowed_updates' => ['message', 'callback_query'], 'max_connections' => 25], $channel->config);
    }

    public function test_the_settings_of_a_changed_channel_follow_its_stored_type(): void
    {
        $channel = $this->channel(['type' => ChannelTypeEnum::WhatsApp, 'config' => ['old' => 'x']]);

        $this->actingAs($this->admin());

        // The Telegram fields are no rules for a channel of another type, so its own are required.
        $this->put($this->listUrl("/{$channel->getKey()}"), $this->telegramPayload())->assertSessionHasErrors('config_entries');

        $this->put($this->listUrl("/{$channel->getKey()}"), ['is_active' => true, 'config_entries' => [['key' => 'phone_id', 'value' => '9']]])
            ->assertRedirect();

        $this->assertEquals(['phone_id' => '9'], $channel->refresh()->config);
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
            ->assertInertia(fn (AssertableInertia $page) => $page->where('channel.telegram.allowedUpdates', ['message'])->etc());
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

        Bus::swap($this->realBus);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

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

    public function test_a_save_that_runs_the_provider_job_in_the_request_still_answers_for_the_assistant(): void
    {
        // The job switches tenants, which resets the current assistant: the redirect must not need it afterwards.
        Bus::swap($this->realBus);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'help_bot']])]);

        $this->actingAs($this->admin())
            ->post($this->listUrl(), $this->telegramPayload())
            ->assertRedirect("/assistant/{$this->assistant->getKey()}/channels")
            ->assertInertiaFlash('success', 'Channel created.');

        $channel = Channel::query()->sole();

        $this->assertSame('help_bot', $channel->telegram_bot_username);

        $this->put($this->listUrl("/{$channel->getKey()}"), $this->telegramPayload(['is_active' => false]))->assertRedirect()->assertInertiaFlash('success');
        $this->post($this->listUrl("/{$channel->getKey()}/rotate-webhook"))->assertRedirect()->assertInertiaFlash('success');
        $this->delete($this->listUrl("/{$channel->getKey()}"))->assertRedirect()->assertInertiaFlash('success', 'Channel deleted.');
    }

    public function test_a_provider_that_refuses_after_the_channel_is_stored_is_worded_without_the_exception(): void
    {
        Bus::swap($this->realBus);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $response = $this->actingAs($this->admin())
            ->post($this->listUrl(), $this->telegramPayload())
            ->assertRedirect("/assistant/{$this->assistant->getKey()}/channels")
            ->assertInertiaFlash('error', 'The channel is saved, but Telegram rejected the webhook registration. Check the token and save again.');

        // The record is there for the user to correct, and nothing of the request, the token among it, is shown.
        $this->assertSame(1, Channel::query()->count());
        $this->assertStringNotContainsString(self::TOKEN, (string) $response->getContent());
        $this->assertStringNotContainsString(self::TOKEN, json_encode(session()->all(), JSON_THROW_ON_ERROR));
    }

    public function test_a_refusal_while_rotating_or_deleting_is_a_toast_not_a_server_error(): void
    {
        $channel = $this->channel(['token' => self::TOKEN]);

        Bus::swap($this->realBus);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $this->actingAs($this->admin());

        $this->post($this->listUrl("/{$channel->getKey()}/rotate-webhook"))
            ->assertRedirect()
            ->assertInertiaFlash('error', 'The webhook hash is changed, but Telegram rejected the webhook registration. Check the token and save the channel again.');

        $this->delete($this->listUrl("/{$channel->getKey()}"))
            ->assertRedirect()
            ->assertInertiaFlash('error', 'The channel is deleted, but Telegram did not confirm removing its webhook.');

        $this->assertModelMissing($channel);
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
        $this->get($this->listUrl())->assertRedirect(route('filament.admin.auth.login'));
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
