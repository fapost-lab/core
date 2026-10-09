<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Contact\Models\ContactTag;
use App\Domains\Flow\Contracts\VariableSchemaRegistryInterface;
use App\Domains\Flow\State\Variables\VariableType;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia;

/**
 * Contacts on the Inertia console: which contacts an assistant sees (tenant and channel link), the list with its search,
 * sort and filters, the card, and the two writes the card has, tags and groups.
 */
final class ContactsConsoleTest extends InertiaConsoleTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string OTHER_TENANT_ID = '00000000-0000-0000-0000-0000000000ff';

    private Assistant $assistant;

    private Assistant $sibling;

    private Channel $channel;

    private Channel $siblingChannel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());

        // Saving a channel reaches Redis and the provider through its observer; neither is wanted here.
        $this->app->instance(ChannelWebhookRegistryInterface::class, new class () implements ChannelWebhookRegistryInterface {
            public function set(Channel $channel, TenantInterface $tenant): void
            {
            }

            public function remove(string $webhookPublicHash): void
            {
            }

            public function warmup(TenantInterface $tenant): void
            {
            }
        });
        Model::clearBootedModels();
        Bus::fake([SyncChannelWebhookJob::class]);

        $this->assistant      = Assistant::factory()->create();
        $this->sibling        = Assistant::factory()->create();
        $this->channel        = $this->channelOf($this->assistant);
        $this->siblingChannel = $this->channelOf($this->sibling);
    }

    public function test_the_list_shows_only_the_contacts_that_wrote_to_the_assistants_channels(): void
    {
        $mine = $this->contact(['external_id' => '1001', 'language' => 'ru', 'meta' => ['first_name' => 'Anna', 'last_name' => 'Ivanova']]);
        $this->contact(['external_id' => '2002'], channel: $this->siblingChannel);
        $this->contact(['external_id' => '3003'], channel: false);
        $this->contact(['external_id' => '4004', 'tenant_id' => self::OTHER_TENANT_ID], channel: $this->channel);

        $this->actingAs($this->admin())
            ->get($this->listUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Contacts/Index')
                ->where('table.meta.total', 1)
                ->where('table.state', ['search' => '', 'sort' => '-updated_at', 'perPage' => 25, 'filters' => []])
                ->where('table.defaults', ['sort' => '-updated_at', 'perPage' => 25, 'perPageOptions' => [25, 50, 100]])
                ->where('table.rows.0.id', (string) $mine->getKey())
                ->where('table.rows.0.shortId', mb_substr((string) $mine->getKey(), 0, 8))
                ->where('table.rows.0.platform', 'telegram')
                ->where('table.rows.0.externalId', '1001')
                ->where('table.rows.0.name', 'Anna Ivanova')
                ->where('table.rows.0.language', 'ru')
                ->where('table.rows.0.viewUrl', $this->cardPath($mine))
                ->has('table.rows.0.createdAt')
                ->where('platforms', [
                    ['value' => 'telegram', 'label' => 'Telegram'],
                    ['value' => 'whatsapp', 'label' => 'WhatsApp'],
                    ['value' => 'email', 'label' => 'Email'],
                ])
                ->where('urls', ['index' => "/assistant/{$this->assistant->getKey()}/contacts"])
                ->etc());
    }

    public function test_a_contact_linked_to_two_channels_of_the_assistant_is_one_row(): void
    {
        $contact = $this->contact();
        ChannelContact::query()->create(['contact_id' => $contact->getKey(), 'channel_id' => $this->channelOf($this->assistant)->getKey()]);

        $this->actingAs($this->admin())
            ->get($this->listUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.total', 1)->has('table.rows', 1)->etc());
    }

    public function test_a_row_name_falls_back_to_the_username_and_then_to_nothing(): void
    {
        $this->contact(['external_id' => '1', 'meta' => ['username' => 'anna_k']]);
        $this->contact(['external_id' => '2', 'meta' => []]);

        $this->actingAs($this->admin())
            ->get($this->listUrl('?search=1'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.rows.0.name', '@anna_k')->etc());

        $this->get($this->listUrl('?search=2'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.rows.0.name', null)->where('table.rows.0.language', 'en')->etc());
    }

    public function test_the_list_sorts_by_the_declared_columns_and_ignores_others(): void
    {
        $old   = $this->contact(['external_id' => 'old']);
        $fresh = $this->contact(['external_id' => 'fresh']);
        $old->forceFill(['created_at' => Carbon::now()->subDays(2), 'updated_at' => Carbon::now()->subDay()])->save();
        $fresh->forceFill(['created_at' => Carbon::now()->subDay(), 'updated_at' => Carbon::now()->subDays(2)])->save();

        $this->actingAs($this->admin());

        // The default is the most recently updated first.
        $this->get($this->listUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.rows.0.externalId', 'old')->etc());

        $this->get($this->listUrl('?sort=created_at'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows.0.externalId', 'old')
                ->where('table.state.sort', 'created_at')
                ->etc());

        $this->get($this->listUrl('?sort=-created_at'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.rows.0.externalId', 'fresh')->etc());

        $this->get($this->listUrl('?sort=external_id'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.state.sort', '-updated_at')->etc());
    }

    public function test_the_list_searches_the_channel_user_id_ignoring_case_and_wildcards(): void
    {
        $this->contact(['external_id' => 'Alpha_77']);
        $this->contact(['external_id' => 'alphaX77']);
        $this->contact(['external_id' => '100%']);

        $this->actingAs($this->admin());

        $this->get($this->listUrl('?search=ALPHA_'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.externalId', 'Alpha_77')
                ->etc());

        $this->get($this->listUrl('?search=' . urlencode('%')))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.total', 1)->where('table.rows.0.externalId', '100%')->etc());
    }

    public function test_the_list_filters_by_platform_and_language_and_ignores_unusable_values(): void
    {
        $this->contact(['external_id' => 'tg-ru', 'platform' => 'telegram', 'language' => 'ru']);
        $this->contact(['external_id' => 'wa-en', 'platform' => 'whatsapp', 'language' => 'en']);

        $this->actingAs($this->admin());

        $this->get($this->listUrl('?filter[platform]=whatsapp'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.externalId', 'wa-en')
                ->where('table.state.filters', ['platform' => 'whatsapp'])
                ->etc());

        $this->get($this->listUrl('?filter[language]=ru'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.externalId', 'tg-ru')
                ->where('table.state.filters', ['language' => 'ru'])
                ->etc());

        $this->get($this->listUrl('?filter[platform]=fax&filter[language]=' . urlencode('<script>') . '&filter[language_2]=ru'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 2)
                ->where('table.state.filters', [])
                ->etc());

        $this->get($this->listUrl('?filter[language]=' . str_repeat('a', 11)))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.total', 2)->where('table.state.filters', [])->etc());
    }

    public function test_the_language_options_come_from_this_assistants_contacts_only(): void
    {
        $this->contact(['language' => 'ru']);
        $this->contact(['language' => 'en']);
        $this->contact(['language' => 'ru']);
        $this->contact(['language' => ''], channel: $this->channel);
        $this->contact(['language' => 'de'], channel: $this->siblingChannel);
        $this->contact(['language' => 'fr', 'tenant_id' => self::OTHER_TENANT_ID], channel: $this->channel);

        $this->actingAs($this->admin())
            ->get($this->listUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('languages', ['en', 'ru'])->etc());
    }

    public function test_the_list_is_paged(): void
    {
        foreach (range(1, 30) as $number) {
            $this->contact(['external_id' => sprintf('c%02d', $number)]);
        }

        $this->actingAs($this->admin())
            ->get($this->listUrl('?page=2'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 30)
                ->where('table.meta.perPage', 25)
                ->has('table.rows', 5)
                ->etc());
    }

    public function test_the_card_lays_out_the_contact(): void
    {
        $contact = $this->contact([
            'external_id' => '555',
            'language'    => 'ru',
            'meta'        => ['first_name' => 'Anna', 'username' => 'anna_k', 'zeta' => 'z', 'alpha' => 'a'],
            'attributes'  => [
                'city'    => 'Kyiv',
                'age'     => 30,
                'survey'  => ['q2' => 'b', 'q1' => 'a'],
                'address' => ['street' => 'Main'],
            ],
        ]);
        ContactTag::factory()->create(['contact_id' => $contact->getKey(), 'tag' => 'vip']);
        ContactTag::factory()->create(['contact_id' => $contact->getKey(), 'tag' => 'beta']);
        $contact->groups()->attach([
            $this->group('Zebras')->getKey(),
            $this->group('Alpacas')->getKey(),
            $this->group('Foreign', self::OTHER_TENANT_ID)->getKey(),
        ]);

        $this->actingAs($this->admin())
            ->get($this->cardUrl($contact))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Contacts/Show')
                ->where('contact.id', (string) $contact->getKey())
                ->where('contact.platform', 'telegram')
                ->where('contact.externalId', '555')
                ->where('contact.language', 'ru')
                ->where('contact.username', 'anna_k')
                ->where('contact.name', 'Anna')
                ->where('contact.tags', ['beta', 'vip'])
                ->where('contact.groups.0.name', 'Alpacas')
                ->where('contact.groups.1.name', 'Zebras')
                ->has('contact.groups', 2)
                ->where('card.profile', [['key' => 'age', 'value' => '30'], ['key' => 'city', 'value' => 'Kyiv']])
                ->where('card.groups.0.key', 'address')
                ->where('card.groups.0.fieldsLabel', '1 field')
                ->where('card.groups.1.key', 'survey')
                ->where('card.groups.1.fieldsLabel', '2 fields')
                ->where('card.groups.1.fields', [['key' => 'q1', 'value' => 'a'], ['key' => 'q2', 'value' => 'b']])
                ->where('card.meta.0', ['key' => 'alpha', 'value' => 'a'])
                ->where('card.collapsed', false)
                ->where('can', ['update' => true])
                ->where('urls.index', "/assistant/{$this->assistant->getKey()}/contacts")
                ->where('urls.tags', $this->cardPath($contact) . '/tags')
                ->where('urls.groups', $this->cardPath($contact) . '/groups')
                ->etc());
    }

    public function test_the_card_formats_values_by_the_declared_type(): void
    {
        $this->app->instance(VariableSchemaRegistryInterface::class, new class () implements VariableSchemaRegistryInterface {
            public function get(string $storage, ?string $group, string $name): ?VariableType
            {
                return match ($name) {
                    'budget' => VariableType::Number,
                    'agreed' => VariableType::Confirm,
                    default  => null,
                };
            }

            public function getAllForTenant(): array
            {
                return [];
            }

            public function getProperties(string $storage, ?string $group, string $name): array
            {
                return [];
            }

            public function invalidate(): void
            {
            }
        });

        $contact = $this->contact(['attributes' => ['deal' => ['budget' => 12500, 'agreed' => 'yes', 'note' => 'ok']]]);

        $this->actingAs($this->admin())
            ->get($this->cardUrl($contact))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('card.groups.0.fields', [
                    ['key' => 'agreed', 'value' => 'Yes'],
                    ['key' => 'budget', 'value' => '12,500'],
                    ['key' => 'note', 'value' => 'ok'],
                ])
                ->etc());
    }

    public function test_the_card_collapses_past_three_groups(): void
    {
        $three = $this->contact(['attributes' => ['a' => ['x' => 1], 'b' => ['x' => 1], 'c' => ['x' => 1]]]);
        $four  = $this->contact(['attributes' => ['a' => ['x' => 1], 'b' => ['x' => 1], 'c' => ['x' => 1], 'd' => ['x' => 1]]]);

        $this->actingAs($this->admin());

        $this->get($this->cardUrl($three))->assertInertia(fn (AssertableInertia $page) => $page->where('card.collapsed', false)->etc());
        $this->get($this->cardUrl($four))->assertInertia(fn (AssertableInertia $page) => $page->where('card.collapsed', true)->etc());
    }

    public function test_the_card_of_an_empty_contact_has_no_sections(): void
    {
        $contact = $this->contact(['language' => '']);

        $this->actingAs($this->admin())
            ->get($this->cardUrl($contact))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('contact.language', null)
                ->where('contact.username', null)
                ->where('contact.name', null)
                ->where('contact.tags', [])
                ->where('contact.groups', [])
                ->where('card', ['profile' => [], 'groups' => [], 'meta' => [], 'collapsed' => false])
                ->etc());
    }

    public function test_the_card_offers_the_tag_vocabulary_and_the_groups_to_one_who_may_change_the_contact(): void
    {
        $contact = $this->contact();
        ContactTag::factory()->create(['contact_id' => $this->contact()->getKey(), 'tag' => 'other-contact-tag']);
        $this->group('Zebras');
        $this->group('Alpacas');
        $this->group('Foreign', self::OTHER_TENANT_ID);

        $this->actingAs($this->userWith(Permission::ManageContacts))
            ->get($this->cardUrl($contact))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.update', true)
                ->where('tagSuggestions', ['other-contact-tag'])
                ->has('groupOptions', 2)
                ->where('groupOptions.0.name', 'Alpacas')
                ->where('groupOptions.1.name', 'Zebras')
                ->etc());
    }

    public function test_a_viewer_reads_but_gets_no_writes_nor_the_tenants_vocabulary(): void
    {
        $contact = $this->contact();
        $group   = $this->group('Readable');
        ContactTag::factory()->create(['contact_id' => $contact->getKey(), 'tag' => 'kept']);

        $this->actingAs($this->userWith(Permission::ViewContacts))
            ->get($this->listUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.total', 1)->etc());

        $this->get($this->cardUrl($contact))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can', ['update' => false])
                ->missing('tagSuggestions')
                ->missing('groupOptions')
                ->missing('urls.tags')
                ->missing('urls.groups')
                ->where('contact.tags', ['kept'])
                ->etc());

        $this->putJson($this->cardUrl($contact) . '/tags', ['tags' => ['hacked']])->assertForbidden();
        $this->putJson($this->cardUrl($contact) . '/groups', ['groups' => [$group->getKey()]])->assertForbidden();

        $this->assertSame(['kept'], $contact->tags()->pluck('tag')->all());
        $this->assertSame(0, $contact->groups()->count());
    }

    public function test_a_user_without_contact_permissions_is_refused_everywhere(): void
    {
        $contact = $this->contact();

        $this->actingAs($this->userWith(Permission::ManageAssistants));

        $this->get($this->listUrl())->assertForbidden();
        $this->get($this->cardUrl($contact))->assertForbidden();
        $this->putJson($this->cardUrl($contact) . '/tags', ['tags' => []])->assertForbidden();
        $this->putJson($this->cardUrl($contact) . '/groups', ['groups' => []])->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->get($this->listUrl())->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_a_contact_the_assistant_does_not_see_is_not_found_everywhere(): void
    {
        $siblings = $this->contact(['external_id' => 'sib'], channel: $this->siblingChannel);
        $foreign  = $this->contact(['external_id' => 'for', 'tenant_id' => self::OTHER_TENANT_ID], channel: $this->channel);
        $loose    = $this->contact(['external_id' => 'loose'], channel: false);
        $group    = $this->group('Mine');

        $this->actingAs($this->admin());

        foreach ([$siblings->getKey(), $foreign->getKey(), $loose->getKey(), 'not-a-uuid'] as $id) {
            $url = $this->listUrl("/{$id}");

            $this->get($url)->assertNotFound();
            $this->putJson($url . '/tags', ['tags' => ['x']])->assertNotFound();
            $this->putJson($url . '/groups', ['groups' => [$group->getKey()]])->assertNotFound();
        }

        $this->assertSame(0, ContactTag::query()->count());
        $this->assertSame(0, $group->contacts()->count());
    }

    public function test_the_tags_are_replaced_by_the_set_sent(): void
    {
        $contact = $this->contact();
        $flowId  = '00000000-0000-0000-0000-00000000f10e';
        ContactTag::factory()->create(['contact_id' => $contact->getKey(), 'tag' => 'old', 'tagged_by' => $flowId]);
        ContactTag::factory()->create(['contact_id' => $contact->getKey(), 'tag' => 'vip', 'tagged_by' => $flowId]);

        $user = $this->admin();

        $this->actingAs($user)
            ->putJson($this->cardUrl($contact) . '/tags', ['tags' => ['vip', ' new ', 'new', '']])
            ->assertRedirect($this->cardUrl($contact))
            ->assertInertiaFlash('success', 'Tags saved.');

        $this->assertSame(['new', 'vip'], $contact->tags()->orderBy('tag')->pluck('tag')->all());
        $this->assertSame((string) $user->getKey(), ContactTag::query()->where('tag', 'new')->value('tagged_by'));
        $this->assertSame($flowId, ContactTag::query()->where('tag', 'vip')->value('tagged_by'));

        // The same set again changes nothing.
        $this->putJson($this->cardUrl($contact) . '/tags', ['tags' => ['vip', 'new']])->assertRedirect();
        $this->assertSame(['new', 'vip'], $contact->tags()->orderBy('tag')->pluck('tag')->all());
        $this->assertSame($flowId, ContactTag::query()->where('tag', 'vip')->value('tagged_by'));

        $this->putJson($this->cardUrl($contact) . '/tags', ['tags' => []])->assertRedirect();
        $this->assertSame(0, $contact->tags()->count());
    }

    public function test_the_tags_are_validated(): void
    {
        $contact = $this->contact();
        ContactTag::factory()->create(['contact_id' => $contact->getKey(), 'tag' => 'kept']);

        $this->actingAs($this->admin());
        $url = $this->cardUrl($contact) . '/tags';

        $this->putJson($url, [])->assertJsonValidationErrors('tags');
        $this->putJson($url, ['tags' => 'vip'])->assertJsonValidationErrors('tags');
        $this->putJson($url, ['tags' => [str_repeat('a', 256)]])->assertJsonValidationErrors('tags.0');
        $this->putJson($url, ['tags' => [['nested']]])->assertJsonValidationErrors('tags.0');

        $this->assertSame(['kept'], $contact->tags()->pluck('tag')->all());
    }

    public function test_the_groups_are_replaced_by_the_set_sent(): void
    {
        $contact = $this->contact();
        $other   = $this->contact();
        $first   = $this->group('First');
        $second  = $this->group('Second');
        $third   = $this->group('Third');
        $contact->groups()->attach($third->getKey());
        $other->groups()->attach($third->getKey());

        $this->actingAs($this->admin())
            ->putJson($this->cardUrl($contact) . '/groups', ['groups' => [$first->getKey(), $second->getKey()]])
            ->assertRedirect($this->cardUrl($contact))
            ->assertInertiaFlash('success', 'Groups saved.');

        $this->assertEqualsCanonicalizing([$first->getKey(), $second->getKey()], $contact->groups()->pluck('contact_groups.id')->all());

        // Leaving a group touches neither the group nor its other members.
        $this->assertSame([$other->getKey()], $third->contacts()->pluck('contacts.id')->all());

        $this->putJson($this->cardUrl($contact) . '/groups', ['groups' => []])->assertRedirect();
        $this->assertSame(0, $contact->groups()->count());
        $this->assertSame(3, ContactGroup::query()->count());
    }

    public function test_the_groups_are_validated_and_a_group_of_another_tenant_is_refused(): void
    {
        $contact = $this->contact();
        $mine    = $this->group('Mine');
        $foreign = $this->group('Foreign', self::OTHER_TENANT_ID);
        $contact->groups()->attach($mine->getKey());

        $this->actingAs($this->admin());
        $url = $this->cardUrl($contact) . '/groups';

        $this->putJson($url, [])->assertJsonValidationErrors('groups');
        $this->putJson($url, ['groups' => [$foreign->getKey()]])->assertJsonValidationErrors('groups.0');
        $this->putJson($url, ['groups' => ['not-a-uuid']])->assertJsonValidationErrors('groups.0');
        $this->putJson($url, ['groups' => [$mine->getKey(), $mine->getKey()]])->assertJsonValidationErrors('groups.0');
        $this->putJson($url, ['groups' => (string) $mine->getKey()])->assertJsonValidationErrors('groups');

        $this->assertSame([$mine->getKey()], $contact->groups()->pluck('contact_groups.id')->all());
    }

    private function listUrl(string $suffix = ''): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/contacts{$suffix}");
    }

    private function cardUrl(Contact $contact): string
    {
        return $this->listUrl("/{$contact->getKey()}");
    }

    private function cardPath(Contact $contact): string
    {
        return "/assistant/{$this->assistant->getKey()}/contacts/{$contact->getKey()}";
    }

    private function channelOf(Assistant $assistant): Channel
    {
        return Channel::factory()->create(['assistant_id' => $assistant->getKey()]);
    }

    /**
     * A contact written to a channel: this assistant's by default, the given one, or none (`false`).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function contact(array $attributes = [], Channel|false|null $channel = null): Contact
    {
        $contact = Contact::factory()->forTenant(self::TENANT_ID)->create($attributes);

        $channel ??= $this->channel;

        if (false !== $channel) {
            ChannelContact::query()->create(['contact_id' => $contact->getKey(), 'channel_id' => $channel->getKey()]);
        }

        return $contact;
    }

    private function group(string $name, string $tenantId = self::TENANT_ID): ContactGroup
    {
        return ContactGroup::query()->create(['tenant_id' => $tenantId, 'name' => $name]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    private function userWith(Permission $permission): User
    {
        $user = User::factory()->create();
        // Opening an assistant's console needs ManageAssistants; the contact permission is what the screen asks on top.
        $user->givePermissionTo([Permission::ManageAssistants->value, $permission->value]);
        $user->assistants()->attach($this->assistant);

        return $user;
    }
}
