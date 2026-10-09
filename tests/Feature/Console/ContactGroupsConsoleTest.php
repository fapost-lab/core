<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

/**
 * Contact groups on the Inertia console: the list (search, sort, pages, tenant scope), create, change and delete,
 * and who may do which.
 */
final class ContactGroupsConsoleTest extends InertiaConsoleTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string OTHER_TENANT_ID = '00000000-0000-0000-0000-0000000000ff';

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->assistant = Assistant::factory()->create();
    }

    public function test_the_list_shows_the_groups_of_the_tenant_with_contact_counts(): void
    {
        $alpha = $this->group('Alpha', 'First group');
        $this->group('Beta');
        $this->group('Foreign', tenantId: self::OTHER_TENANT_ID);

        $alpha->contacts()->attach([
            Contact::factory()->forTenant(self::TENANT_ID)->create()->getKey(),
            Contact::factory()->forTenant(self::TENANT_ID)->create()->getKey(),
        ]);

        $this->actingAs($this->admin())
            ->get($this->listUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/ContactGroups/Index')
                ->where('table.meta.total', 2)
                ->where('table.state', ['search' => '', 'sort' => 'name', 'perPage' => 25])
                ->has('table.rows', 2)
                ->where('table.rows.0.id', (string) $alpha->getKey())
                ->where('table.rows.0.name', 'Alpha')
                ->where('table.rows.0.description', 'First group')
                ->where('table.rows.0.contactsCount', 2)
                ->where('table.rows.0.editUrl', "/assistant/{$this->assistant->getKey()}/contact-groups/{$alpha->getKey()}/edit")
                ->where('table.rows.0.deleteUrl', "/assistant/{$this->assistant->getKey()}/contact-groups/{$alpha->getKey()}")
                ->where('table.rows.1.name', 'Beta')
                ->where('table.rows.1.contactsCount', 0)
                ->where('can', ['create' => true, 'update' => true, 'delete' => true])
                ->where('urls.create', "/assistant/{$this->assistant->getKey()}/contact-groups/create")
                ->etc());
    }

    public function test_the_list_searches_by_name_ignoring_case_and_wildcards(): void
    {
        $this->group('Premium customers');
        $this->group('Newsletter');
        $this->group('100% fans');

        $this->actingAs($this->admin());

        $this->get($this->listUrl('?search=PREMIUM'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.name', 'Premium customers')
                ->where('table.state.search', 'PREMIUM')
                ->etc());

        // A `%` in the text is a literal percent sign, not "anything".
        $this->get($this->listUrl('?search=' . urlencode('%')))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.name', '100% fans')
                ->etc());
    }

    public function test_the_list_sorts_by_a_whitelisted_column_and_ignores_others(): void
    {
        $old = $this->group('Alpha');
        $old->forceFill(['created_at' => Carbon::now()->subDay()])->save();
        $this->group('Beta');

        $this->actingAs($this->admin());

        $this->get($this->listUrl('?sort=-name'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows.0.name', 'Beta')
                ->where('table.state.sort', '-name')
                ->etc());

        $this->get($this->listUrl('?sort=-created_at'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.rows.0.name', 'Beta')->etc());

        $this->get($this->listUrl('?sort=created_at'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.rows.0.name', 'Alpha')->etc());

        $this->get($this->listUrl('?sort=tenant_id;drop'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.state.sort', 'name')->etc());
    }

    public function test_the_list_is_paged_and_falls_back_on_an_unknown_page_size(): void
    {
        foreach (range(1, 30) as $number) {
            $this->group(sprintf('Group %02d', $number));
        }

        $this->actingAs($this->admin());

        $this->get($this->listUrl('?page=2'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta', ['total' => 30, 'perPage' => 25, 'currentPage' => 2, 'lastPage' => 2, 'from' => 26, 'to' => 30])
                ->has('table.rows', 5)
                ->etc());

        $this->get($this->listUrl('?per_page=50'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.lastPage', 1)->has('table.rows', 30)->etc());

        $this->get($this->listUrl('?per_page=7'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.state.perPage', 25)->etc());

        // A page past the end shows the last one.
        $this->get($this->listUrl('?page=9'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.currentPage', 2)->etc());
    }

    public function test_a_group_is_created_and_the_user_is_sent_back_to_the_list_with_a_message(): void
    {
        $this->actingAs($this->admin())
            ->post($this->listUrl(), ['name' => 'VIP', 'description' => 'Best customers'])
            ->assertRedirect($this->listUrl())
            ->assertInertiaFlash('success');

        $this->assertDatabaseHas('contact_groups', [
            'tenant_id'   => self::TENANT_ID,
            'name'        => 'VIP',
            'description' => 'Best customers',
        ]);
    }

    public function test_the_create_screen_opens(): void
    {
        $this->actingAs($this->admin())
            ->get($this->listUrl('/create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/ContactGroups/Create')
                ->where('urls.submit', "/assistant/{$this->assistant->getKey()}/contact-groups")
                ->etc());
    }

    public function test_creating_validates_the_fields(): void
    {
        $this->group('Taken');

        $this->actingAs($this->admin());

        $this->post($this->listUrl(), ['name' => ''])->assertSessionHasErrors('name');
        $this->post($this->listUrl(), ['name' => str_repeat('a', 256)])->assertSessionHasErrors('name');
        $this->post($this->listUrl(), ['name' => 'Ok', 'description' => str_repeat('a', 1001)])->assertSessionHasErrors('description');
        $this->post($this->listUrl(), ['name' => 'Taken'])->assertSessionHasErrors('name');

        $this->assertSame(1, ContactGroup::query()->count());
    }

    public function test_the_same_name_may_exist_in_another_tenant(): void
    {
        $this->group('Shared name', tenantId: self::OTHER_TENANT_ID);

        $this->actingAs($this->admin())
            ->post($this->listUrl(), ['name' => 'Shared name'])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(1, ContactGroup::query()->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_a_group_is_changed(): void
    {
        $group = $this->group('Old', 'Old text');

        $this->actingAs($this->admin());

        $this->get($this->listUrl("/{$group->getKey()}/edit"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/ContactGroups/Edit')
                ->where('group', ['id' => (string) $group->getKey(), 'name' => 'Old', 'description' => 'Old text'])
                ->where('urls.submit', "/assistant/{$this->assistant->getKey()}/contact-groups/{$group->getKey()}")
                ->etc());

        $this->put($this->listUrl("/{$group->getKey()}"), ['name' => 'New', 'description' => null])
            ->assertRedirect($this->listUrl())
            ->assertInertiaFlash('success');

        $this->assertDatabaseHas('contact_groups', ['id' => $group->getKey(), 'name' => 'New', 'description' => null]);
    }

    public function test_a_group_may_keep_its_own_name_but_not_take_another(): void
    {
        $group = $this->group('Mine');
        $this->group('Other');

        $this->actingAs($this->admin());

        $this->put($this->listUrl("/{$group->getKey()}"), ['name' => 'Mine', 'description' => 'Now with text'])
            ->assertSessionDoesntHaveErrors();
        $this->put($this->listUrl("/{$group->getKey()}"), ['name' => 'Other'])->assertSessionHasErrors('name');
    }

    public function test_one_group_is_deleted_with_its_memberships(): void
    {
        $group   = $this->group('Doomed');
        $keep    = $this->group('Kept');
        $contact = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $group->contacts()->attach($contact->getKey());

        $this->actingAs($this->admin())
            ->delete($this->listUrl("/{$group->getKey()}"))
            ->assertRedirect($this->listUrl())
            ->assertInertiaFlash('success');

        $this->assertModelMissing($group);
        $this->assertModelExists($keep);
        $this->assertModelExists($contact);
        $this->assertDatabaseMissing('contact_group_members', ['contact_group_id' => $group->getKey()]);
    }

    public function test_many_groups_are_deleted_and_foreign_ids_are_left_alone(): void
    {
        $one     = $this->group('One');
        $two     = $this->group('Two');
        $kept    = $this->group('Kept');
        $foreign = $this->group('Foreign', tenantId: self::OTHER_TENANT_ID);

        $this->actingAs($this->admin())
            ->delete($this->listUrl(), ['ids' => [$one->getKey(), $two->getKey(), $foreign->getKey()]])
            ->assertRedirect($this->listUrl())
            ->assertInertiaFlash('success', 'Groups deleted: 2.');

        $this->assertModelMissing($one);
        $this->assertModelMissing($two);
        $this->assertModelExists($kept);
        $this->assertModelExists($foreign);
    }

    public function test_a_delete_goes_back_to_the_list_the_user_was_on(): void
    {
        $one = $this->group('One');
        $two = $this->group('Two');
        $url = $this->listUrl('?search=o&sort=-name&page=2');

        $this->actingAs($this->admin())
            ->from($url)
            ->delete($this->listUrl("/{$one->getKey()}"))
            ->assertRedirect($url);

        $this->from($url)
            ->delete($this->listUrl(), ['ids' => [$two->getKey()]])
            ->assertRedirect($url);
    }

    public function test_the_list_tells_the_client_its_defaults(): void
    {
        $this->actingAs($this->admin())
            ->get($this->listUrl('?sort=-created_at&per_page=50'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.defaults', ['sort' => 'name', 'perPage' => 25, 'perPageOptions' => [25, 50, 100]])
                ->where('table.state.perPage', 50)
                ->etc());
    }

    public function test_deleting_many_validates_the_ids(): void
    {
        $this->actingAs($this->admin());

        $this->delete($this->listUrl(), [])->assertSessionHasErrors('ids');
        $this->delete($this->listUrl(), ['ids' => []])->assertSessionHasErrors('ids');
        $this->delete($this->listUrl(), ['ids' => ['not-an-id']])->assertSessionHasErrors('ids.0');
        $this->delete($this->listUrl(), ['ids' => array_fill(0, 101, '00000000-0000-0000-0000-000000000002')])->assertSessionHasErrors('ids');
    }

    public function test_a_group_of_another_tenant_is_not_found(): void
    {
        $foreign = $this->group('Foreign', tenantId: self::OTHER_TENANT_ID);

        $this->actingAs($this->admin());

        $this->get($this->listUrl("/{$foreign->getKey()}/edit"))->assertNotFound();
        $this->put($this->listUrl("/{$foreign->getKey()}"), ['name' => 'Hijacked'])->assertNotFound();
        $this->delete($this->listUrl("/{$foreign->getKey()}"))->assertNotFound();
        $this->get($this->listUrl('/not-an-id/edit'))->assertNotFound();

        $this->assertDatabaseHas('contact_groups', ['id' => $foreign->getKey(), 'name' => 'Foreign']);
    }

    public function test_a_viewer_sees_the_list_without_the_write_controls_and_cannot_write(): void
    {
        $group  = $this->group('Readable');
        $viewer = $this->userWith(Permission::ViewContacts);

        $this->actingAs($viewer)
            ->get($this->listUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('can', ['create' => false, 'update' => false, 'delete' => false])
                ->etc());

        $this->get($this->listUrl('/create'))->assertForbidden();
        $this->post($this->listUrl(), ['name' => 'New'])->assertForbidden();
        $this->get($this->listUrl("/{$group->getKey()}/edit"))->assertForbidden();
        $this->put($this->listUrl("/{$group->getKey()}"), ['name' => 'Changed'])->assertForbidden();
        $this->delete($this->listUrl("/{$group->getKey()}"))->assertForbidden();
        $this->delete($this->listUrl(), ['ids' => [$group->getKey()]])->assertForbidden();

        $this->assertDatabaseHas('contact_groups', ['id' => $group->getKey(), 'name' => 'Readable']);
        $this->assertSame(1, ContactGroup::query()->count());
    }

    public function test_a_manager_may_write(): void
    {
        $this->actingAs($this->userWith(Permission::ManageContacts))
            ->get($this->listUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can', ['create' => true, 'update' => true, 'delete' => true])
                ->etc());
    }

    public function test_a_user_without_contact_permissions_is_refused_everywhere(): void
    {
        $group = $this->group('Hidden');

        $this->actingAs($this->userWith(Permission::ManageAssistants));

        $this->get($this->listUrl())->assertForbidden();
        $this->get($this->listUrl("/{$group->getKey()}/edit"))->assertForbidden();
        $this->delete($this->listUrl(), ['ids' => [$group->getKey()]])->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->get($this->listUrl())->assertRedirect(route('filament.admin.auth.login'));
    }

    private function listUrl(string $suffix = ''): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/contact-groups{$suffix}");
    }

    private function group(string $name, ?string $description = null, string $tenantId = self::TENANT_ID): ContactGroup
    {
        return ContactGroup::query()->create(['tenant_id' => $tenantId, 'name' => $name, 'description' => $description]);
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
