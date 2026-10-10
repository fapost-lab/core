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
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Concerns\SetsRecordLimits;

/**
 * Assistants in the admin panel, served by the console shell in its admin mode: who sees which assistant, the
 * assistant limit, assigning a non-administrator creator, changing and deleting an assistant, and the assistant's
 * channels on the console's channel form without their secrets.
 */
final class AdminAssistantsConsoleTest extends InertiaConsoleTestCase
{
    use SetsRecordLimits;

    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string OTHER_TENANT_ID = '00000000-0000-0000-0000-0000000000ff';

    private const string TOKEN = 'bot-token-SECRET-111';

    private const string SECRET = 'webhook-secret-SECRET-222';

    /**
     * @var object{calls: list<array{0: string, 1: string}>}
     */
    private object $registry;

    private Dispatcher $realBus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
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
        Model::clearBootedModels();

        $this->realBus = Bus::getFacadeRoot();
        Bus::fake([SyncChannelWebhookJob::class]);
    }

    public function test_the_list_shows_the_tenants_assistants_inside_the_admin_shell(): void
    {
        $assistant = Assistant::factory()->create(['name' => 'Support', 'default_language' => 'uk', 'is_active' => true]);
        Assistant::factory()->create(['name' => 'Sales']);
        Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID, 'name' => 'Foreign']);

        $id = (string) $assistant->getKey();

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Assistants/Index')
                ->where('navigation.mode', 'admin')
                ->where('table.meta.total', 2)
                ->where('table.state', ['search' => '', 'sort' => 'name', 'perPage' => 25])
                ->where('table.rows.0.name', 'Sales')
                ->where('table.rows.1', fn ($row): bool => [
                    'id'              => $id,
                    'name'            => 'Support',
                    'isActive'        => true,
                    'defaultLanguage' => 'uk',
                    'languageLabel'   => 'Ukrainian — Українська',
                    'showUrl'         => "/admin/assistants/{$id}",
                    'editUrl'         => "/admin/assistants/{$id}/edit",
                    'consoleUrl'      => "/assistant/{$id}/dashboard",
                    'can'             => ['view' => true, 'update' => true],
                ] === array_diff_key($row->toArray(), ['updatedAt' => 1]))
                ->where('limit', ['reached' => false, 'hint' => null])
                ->where('can', ['create' => true])
                ->where('urls', ['index' => '/admin/assistants', 'create' => '/admin/assistants/create'])
                ->etc());

        $this->get($this->url('?search=supp'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.total', 1)->where('table.rows.0.id', $id)->etc());
    }

    public function test_the_admin_menu_links_to_the_list_inside_the_shell(): void
    {
        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('navigation.groups', fn ($groups): bool => collect($groups)
                    ->flatMap(static fn (array $group): array => $group['items'])
                    ->contains(static fn (array $item): bool => 'filament.admin.resources.assistants.index' === $item['key'] && false === $item['external']))
                ->etc());
    }

    public function test_a_manager_sees_only_the_assistants_assigned_to_them(): void
    {
        $assigned   = Assistant::factory()->create(['name' => 'Mine']);
        $unassigned = Assistant::factory()->create(['name' => 'Theirs']);
        $manager    = $this->manager($assigned);

        $this->actingAs($manager)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.id', (string) $assigned->getKey())
                ->etc());

        $this->get($this->url("/{$assigned->getKey()}"))->assertOk();
        $this->get($this->url("/{$unassigned->getKey()}"))->assertNotFound();
        $this->get($this->url("/{$unassigned->getKey()}/edit"))->assertNotFound();
        $this->put($this->url("/{$unassigned->getKey()}"), $this->payload())->assertNotFound();
        $this->delete($this->url("/{$unassigned->getKey()}"))->assertNotFound();
        $this->get($this->url('/not-a-ulid'))->assertNotFound();

        $this->assertModelExists($unassigned);
        $this->assertSame('Theirs', $unassigned->refresh()->name);
    }

    public function test_another_tenants_assistant_is_not_found_even_for_an_administrator(): void
    {
        $foreign = Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]);

        $this->actingAs($this->admin());

        $this->get($this->url("/{$foreign->getKey()}"))->assertNotFound();
        $this->delete($this->url("/{$foreign->getKey()}"))->assertNotFound();
        $this->assertModelExists($foreign);
    }

    public function test_without_the_assistants_permission_every_screen_is_refused(): void
    {
        $assistant = Assistant::factory()->create();
        $user      = User::factory()->create();
        $user->assistants()->attach($assistant);

        $this->actingAs($user);

        $this->get($this->url())->assertForbidden();
        $this->get($this->url('/create'))->assertForbidden();
        $this->post($this->url(), $this->payload())->assertForbidden();
        $this->get($this->url("/{$assistant->getKey()}"))->assertForbidden();
        $this->get($this->url("/{$assistant->getKey()}/edit"))->assertForbidden();
        $this->put($this->url("/{$assistant->getKey()}"), $this->payload())->assertForbidden();
        $this->delete($this->url("/{$assistant->getKey()}"))->assertForbidden();

        $this->assertSame(1, Assistant::query()->count());
    }

    public function test_a_manager_creates_an_assistant_and_is_assigned_to_it(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->get($this->url('/create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Assistants/Create')
                ->where('languages.0', ['value' => 'ar', 'label' => 'Arabic — العربية'])
                ->where('urls', ['index' => '/admin/assistants', 'submit' => '/admin/assistants'])
                ->etc());

        $this->post($this->url(), $this->payload(['name' => 'Support Bot', 'default_language' => 'ru', 'is_active' => false]))
            ->assertRedirect($this->url())
            ->assertInertiaFlash('success', 'Assistant created.');

        $assistant = Assistant::query()->where('name', 'Support Bot')->firstOrFail();

        $this->assertSame(self::TENANT_ID, $assistant->tenant_id);
        $this->assertSame('ru', $assistant->default_language);
        $this->assertFalse($assistant->is_active);
        $this->assertSame([(string) $assistant->getKey()], $manager->assistants()->pluck('assistants.id')->map(static fn ($id): string => (string) $id)->all());
    }

    public function test_an_administrator_creates_an_assistant_without_being_assigned(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post($this->url(), $this->payload())->assertRedirect($this->url());

        $this->assertSame(1, Assistant::query()->count());
        $this->assertSame(0, $admin->assistants()->count());
    }

    public function test_the_form_is_validated(): void
    {
        $this->actingAs($this->admin())
            ->post($this->url(), ['name' => '', 'default_language' => 'xx'])
            ->assertSessionHasErrors(['name', 'default_language', 'is_active']);

        $this->assertSame(0, Assistant::query()->count());
    }

    public function test_at_the_limit_creating_is_closed_even_for_an_administrator(): void
    {
        Assistant::factory()->create();
        $this->limitRecords(Assistant::LIMIT_KEY, 1);

        $this->actingAs($this->admin());

        $this->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('limit', ['reached' => true, 'hint' => 'Limit reached (1 of 1)'])
                ->where('can.create', false)
                ->etc());

        $this->get($this->url('/create'))->assertForbidden();
    }

    public function test_a_create_that_lost_the_race_for_the_last_slot_is_refused_with_a_message(): void
    {
        Assistant::factory()->create();
        $this->limitRecords(Assistant::LIMIT_KEY, 1);

        // Not back to the create page, which is closed at the limit: the list, with the hint and the toast.
        $this->actingAs($this->manager())
            ->from($this->url('/create'))
            ->followingRedirects()
            ->post($this->url(), $this->payload())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Assistants/Index')
                ->url('/admin/assistants')
                ->where('limit', ['reached' => true, 'hint' => 'Limit reached (1 of 1)'])
                ->hasFlash('error', 'Assistant limit reached. Assistants limit reached: 1 of 1.')
                ->etc());

        $this->assertSame(1, Assistant::query()->count());
    }

    public function test_the_page_shows_the_assistant_and_its_channels_without_their_secrets(): void
    {
        $assistant = Assistant::factory()->create(['name' => 'Support', 'default_language' => 'en']);
        $channel   = $this->channel($assistant, ['token' => self::TOKEN, 'secret_token' => self::SECRET]);
        $this->channel(Assistant::factory()->create());

        $id   = (string) $assistant->getKey();
        $base = "/admin/assistants/{$id}/channels/{$channel->getKey()}";

        $response = $this->actingAs($this->admin())
            ->get($this->url("/{$id}"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Assistants/Show')
                ->where('navigation.mode', 'admin')
                ->where('assistant.name', 'Support')
                ->where('assistant.languageLabel', 'English')
                ->where('channels.table.meta.total', 1)
                ->where('channels.table.rows.0.editUrl', "{$base}/edit")
                ->where('channels.table.rows.0.deleteUrl', $base)
                ->where('channels.table.rows.0.rotateUrl', "{$base}/rotate-webhook")
                ->where('channels.table.rows.0.registerWebhookUrl', "{$base}/register-webhook")
                ->where('channels.can', ['update' => true, 'delete' => true, 'rotate' => true])
                ->where('can', ['update' => true])
                ->where('urls', [
                    'index'   => '/admin/assistants',
                    'show'    => "/admin/assistants/{$id}",
                    'edit'    => "/admin/assistants/{$id}/edit",
                    'console' => "/assistant/{$id}/dashboard",
                ])
                ->etc());

        $response->assertDontSee(self::TOKEN)->assertDontSee(self::SECRET)->assertDontSee($channel->webhook_public_hash);
    }

    public function test_the_channels_section_needs_the_channel_permission(): void
    {
        $assistant = Assistant::factory()->create();
        $this->channel($assistant);

        $this->actingAs($this->manager($assistant))
            ->get($this->url("/{$assistant->getKey()}"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('channels', null)->etc());

        $this->actingAs($this->manager($assistant, Permission::ManageChannels))
            ->get($this->url("/{$assistant->getKey()}"))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('channels.table.meta.total', 1)
                ->where('channels.can', ['update' => true, 'delete' => true, 'rotate' => false])
                ->etc());
    }

    public function test_an_assistant_is_changed_through_the_edit_form(): void
    {
        $assistant = Assistant::factory()->create(['name' => 'Old', 'default_language' => 'en', 'is_active' => true]);
        $id        = (string) $assistant->getKey();

        $this->actingAs($this->admin());

        $this->get($this->url("/{$id}/edit"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Assistants/Edit')
                ->where('assistant', ['id' => $id, 'name' => 'Old', 'isActive' => true, 'defaultLanguage' => 'en'])
                ->where('can', ['delete' => true])
                ->where('urls', [
                    'index'   => '/admin/assistants',
                    'show'    => "/admin/assistants/{$id}",
                    'submit'  => "/admin/assistants/{$id}",
                    'destroy' => "/admin/assistants/{$id}",
                ])
                ->etc());

        $this->put($this->url("/{$id}"), $this->payload(['name' => 'New', 'default_language' => 'de', 'is_active' => false]))
            ->assertRedirect($this->url())
            ->assertInertiaFlash('success', 'Assistant saved.');

        $assistant->refresh();
        $this->assertSame('New', $assistant->name);
        $this->assertSame('de', $assistant->default_language);
        $this->assertFalse($assistant->is_active);
    }

    public function test_deleting_an_assistant_deletes_its_channels_and_their_routing(): void
    {
        $assistant = Assistant::factory()->create();
        $channel   = $this->channel($assistant);
        $hash      = $channel->webhook_public_hash;

        $this->actingAs($this->admin())
            ->delete($this->url("/{$assistant->getKey()}"))
            ->assertRedirect($this->url())
            ->assertInertiaFlash('success', 'Assistant deleted.');

        $this->assertModelMissing($assistant);
        $this->assertModelMissing($channel);
        $this->assertContains(['remove', $hash], $this->registry->calls);
    }

    public function test_a_provider_that_does_not_confirm_removing_a_webhook_is_a_toast_not_a_server_error(): void
    {
        $assistant = Assistant::factory()->create();
        $channel   = $this->channel($assistant, ['token' => self::TOKEN]);

        Bus::swap($this->realBus);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $this->actingAs($this->admin())
            ->delete($this->url("/{$assistant->getKey()}"))
            ->assertRedirect($this->url())
            ->assertInertiaFlash('error', 'The assistant is deleted, but Telegram did not confirm removing the webhook of one of its channels.');

        $this->assertModelMissing($assistant);
        $this->assertModelMissing($channel);
    }

    public function test_a_channel_is_edited_on_the_consoles_form_with_the_admin_urls(): void
    {
        $assistant = Assistant::factory()->create();
        $channel   = $this->channel($assistant, ['token' => self::TOKEN, 'secret_token' => self::SECRET]);
        $base      = "/admin/assistants/{$assistant->getKey()}/channels/{$channel->getKey()}";

        $this->actingAs($this->admin());

        $this->get($this->panelUrl("{$base}/edit"))
            ->assertOk()
            ->assertDontSee(self::TOKEN)
            ->assertDontSee(self::SECRET)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Channels/Edit')
                ->where('navigation.mode', 'admin')
                ->where('channel.id', (string) $channel->getKey())
                ->where('channel.registerWebhookUrl', "{$base}/register-webhook")
                ->where('urls', ['index' => "/admin/assistants/{$assistant->getKey()}", 'submit' => $base])
                ->has('types')
                ->etc());

        $this->put($this->panelUrl($base), [
            'type'         => 'whatsapp',
            'token'        => '',
            'secret_token' => '',
            'is_active'    => true,
            'config'       => ['allowed_updates' => ['message'], 'max_connections' => 10],
        ])
            ->assertRedirect($this->url("/{$assistant->getKey()}"))
            ->assertInertiaFlash('success', 'Channel saved.');

        $channel->refresh();
        $this->assertSame(ChannelTypeEnum::Telegram, $channel->type);
        $this->assertSame(self::TOKEN, $channel->token);
        $this->assertSame(10, $channel->config['max_connections']);
    }

    public function test_a_channel_is_rotated_and_deleted_from_the_assistants_page(): void
    {
        $assistant = Assistant::factory()->create();
        $channel   = $this->channel($assistant);
        $old       = $channel->webhook_public_hash;
        $base      = "/admin/assistants/{$assistant->getKey()}/channels/{$channel->getKey()}";
        $page      = $this->url("/{$assistant->getKey()}?sort=type");

        $this->actingAs($this->admin());

        $response = $this->from($page)->post($this->panelUrl("{$base}/rotate-webhook"))->assertRedirect($page);
        $new      = $channel->refresh()->webhook_public_hash;
        $this->assertNotSame($old, $new);
        $response->assertInertiaFlash('success', "Webhook hash rotated. New hash: {$new}");

        $this->from($page)->delete($this->panelUrl($base))
            ->assertRedirect($page)
            ->assertInertiaFlash('success', 'Channel deleted.');

        $this->assertModelMissing($channel);
        $this->assertContains(['remove', $new], $this->registry->calls);
    }

    public function test_the_channel_actions_answer_to_the_channel_policy_and_the_assistant_in_the_url(): void
    {
        $assistant = Assistant::factory()->create();
        $other     = Assistant::factory()->create();
        $channel   = $this->channel($assistant);
        $hash      = $channel->webhook_public_hash;
        $base      = "/admin/assistants/{$assistant->getKey()}/channels/{$channel->getKey()}";

        // Seeing the assistant is not enough: the channel permission is asked on top, the rotation one for rotating.
        $this->actingAs($this->manager($assistant));
        $this->get($this->panelUrl("{$base}/edit"))->assertForbidden();
        $this->delete($this->panelUrl($base))->assertForbidden();

        $this->actingAs($this->manager($assistant, Permission::ManageChannels));
        $this->post($this->panelUrl("{$base}/rotate-webhook"))->assertForbidden();
        $this->assertSame($hash, $channel->refresh()->webhook_public_hash);

        // A channel under another assistant's URL, or an assistant the user does not see, is not found.
        $this->actingAs($this->admin());
        $this->get($this->panelUrl("/admin/assistants/{$other->getKey()}/channels/{$channel->getKey()}/edit"))->assertNotFound();
        $this->delete($this->panelUrl("/admin/assistants/{$other->getKey()}/channels/{$channel->getKey()}"))->assertNotFound();

        $this->actingAs($this->manager($other, Permission::ManageChannels));
        $this->get($this->panelUrl("{$base}/edit"))->assertNotFound();
        $this->put($this->panelUrl($base), ['is_active' => false])->assertNotFound();

        $this->assertModelExists($channel);
    }

    public function test_a_visitor_is_sent_to_the_sign_in(): void
    {
        $this->get($this->url())->assertRedirect(route('filament.admin.auth.login'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function channel(Assistant $assistant, array $attributes = []): Channel
    {
        return Channel::factory()->create(['assistant_id' => $assistant->getKey(), ...$attributes]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return ['name' => 'Bot', 'default_language' => 'en', 'is_active' => true, ...$overrides];
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    /**
     * A staff member who manages assistants, assigned to the given one, with any further permissions.
     */
    private function manager(?Assistant $assistant = null, Permission ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([Permission::ManageAssistants->value, ...array_map(static fn (Permission $permission): string => $permission->value, $permissions)]);

        if (null !== $assistant) {
            $user->assistants()->attach($assistant);
        }

        return $user;
    }

    private function url(string $suffix = ''): string
    {
        return $this->panelUrl("/admin/assistants{$suffix}");
    }
}
