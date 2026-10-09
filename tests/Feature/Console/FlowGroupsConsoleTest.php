<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowGroup;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * Flow groups on the Inertia console: the list scoped to the assistant, create, change, inline create from a flow's
 * form, and delete with the guard for a group that still has flows.
 */
final class FlowGroupsConsoleTest extends InertiaConsoleTestCase
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

    public function test_the_list_shows_the_groups_of_the_assistant_with_flow_counts(): void
    {
        $alpha = $this->group('Alpha');
        $this->group('Beta');
        $this->group('Elsewhere', assistant: Assistant::factory()->create());
        $this->group('Foreign', tenantId: self::OTHER_TENANT_ID, assistant: Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]));

        FlowDraft::factory()->count(2)->create(['assistant_id' => $this->assistant->getKey(), 'flow_group_id' => $alpha->getKey()]);

        $this->actingAs($this->admin())
            ->get($this->listUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/FlowGroups/Index')
                ->where('table.meta.total', 2)
                ->where('table.state', ['search' => '', 'sort' => 'name', 'perPage' => 25])
                ->where('table.rows.0.id', (string) $alpha->getKey())
                ->where('table.rows.0.name', 'Alpha')
                ->where('table.rows.0.flowsCount', 2)
                ->where('table.rows.0.editUrl', "/assistant/{$this->assistant->getKey()}/flow-groups/{$alpha->getKey()}/edit")
                ->where('table.rows.0.deleteUrl', "/assistant/{$this->assistant->getKey()}/flow-groups/{$alpha->getKey()}")
                ->where('table.rows.1.name', 'Beta')
                ->where('table.rows.1.flowsCount', 0)
                ->where('can', ['create' => true, 'update' => true, 'delete' => true])
                ->where('urls.destroyMany', "/assistant/{$this->assistant->getKey()}/flow-groups")
                ->etc());
    }

    public function test_the_list_searches_and_sorts_by_the_flow_count(): void
    {
        $few  = $this->group('Few');
        $many = $this->group('Many');
        $this->group('Sales');
        FlowDraft::factory()->create(['assistant_id' => $this->assistant->getKey(), 'flow_group_id' => $few->getKey()]);
        FlowDraft::factory()->count(3)->create(['assistant_id' => $this->assistant->getKey(), 'flow_group_id' => $many->getKey()]);

        $this->actingAs($this->admin());

        $this->get($this->listUrl('?search=sal'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.total', 1)->where('table.rows.0.name', 'Sales')->etc());

        $this->get($this->listUrl('?sort=-drafts_count'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows.0.name', 'Many')
                ->where('table.rows.1.name', 'Few')
                ->etc());
    }

    public function test_a_group_is_created_for_the_assistant_and_the_user_is_sent_back_to_the_list(): void
    {
        $this->actingAs($this->admin())
            ->post($this->listUrl(), ['name' => 'Onboarding'])
            ->assertRedirect($this->listUrl())
            ->assertInertiaFlash('success');

        $this->assertDatabaseHas('flow_groups', [
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $this->assistant->getKey(),
            'name'         => 'Onboarding',
        ]);
    }

    public function test_the_create_screen_opens(): void
    {
        $this->actingAs($this->admin())
            ->get($this->listUrl('/create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/FlowGroups/Create')
                ->where('urls.submit', "/assistant/{$this->assistant->getKey()}/flow-groups")
                ->etc());
    }

    public function test_creating_validates_the_name(): void
    {
        $this->actingAs($this->admin());

        $this->post($this->listUrl(), ['name' => ''])->assertSessionHasErrors('name');
        $this->post($this->listUrl(), ['name' => str_repeat('a', 256)])->assertSessionHasErrors('name');

        $this->assertSame(0, FlowGroup::query()->count());
    }

    public function test_a_group_is_created_from_a_flows_form_and_its_id_comes_back_as_flash_data(): void
    {
        $from = $this->panelUrl("/assistant/{$this->assistant->getKey()}/flows/create");

        $response = $this->actingAs($this->admin())
            ->from($from)
            ->post($this->listUrl('/inline'), ['name' => 'Inline'])
            ->assertRedirect($from)
            ->assertInertiaFlash('success');

        $group = FlowGroup::query()->where('name', 'Inline')->sole();

        $this->assertSame((string) $this->assistant->getKey(), $group->assistant_id);
        $response->assertInertiaFlash('flowGroupId', (string) $group->getKey());
    }

    public function test_a_group_is_changed(): void
    {
        $group = $this->group('Old');

        $this->actingAs($this->admin());

        $this->get($this->listUrl("/{$group->getKey()}/edit"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/FlowGroups/Edit')
                ->where('group', ['id' => (string) $group->getKey(), 'name' => 'Old'])
                ->where('urls.submit', "/assistant/{$this->assistant->getKey()}/flow-groups/{$group->getKey()}")
                ->etc());

        $this->put($this->listUrl("/{$group->getKey()}"), ['name' => 'New'])
            ->assertRedirect($this->listUrl())
            ->assertInertiaFlash('success');

        $this->assertDatabaseHas('flow_groups', ['id' => $group->getKey(), 'name' => 'New']);
    }

    public function test_an_empty_group_is_deleted(): void
    {
        $group = $this->group('Doomed');
        $keep  = $this->group('Kept');

        $this->actingAs($this->admin())
            ->delete($this->listUrl("/{$group->getKey()}"))
            ->assertRedirect($this->listUrl())
            ->assertInertiaFlash('success');

        $this->assertModelMissing($group);
        $this->assertModelExists($keep);
    }

    public function test_a_group_with_flows_is_not_deleted_and_the_user_is_told_why(): void
    {
        $group = $this->group('Busy');
        $flow  = FlowDraft::factory()->create(['assistant_id' => $this->assistant->getKey(), 'flow_group_id' => $group->getKey()]);
        $url   = $this->listUrl('?sort=-name');

        $this->actingAs($this->admin())
            ->from($url)
            ->delete($this->listUrl("/{$group->getKey()}"))
            ->assertRedirect($url)
            ->assertInertiaFlash('error');

        $this->assertModelExists($group);
        $this->assertModelExists($flow);
    }

    public function test_many_groups_are_deleted_one_by_one_and_the_ones_with_flows_are_skipped(): void
    {
        $empty   = $this->group('Empty');
        $busy    = $this->group('Busy');
        $foreign = $this->group('Elsewhere', assistant: Assistant::factory()->create());
        FlowDraft::factory()->create(['assistant_id' => $this->assistant->getKey(), 'flow_group_id' => $busy->getKey()]);

        $this->actingAs($this->admin())
            ->delete($this->listUrl(), ['ids' => [$empty->getKey(), $busy->getKey(), $foreign->getKey()]])
            ->assertRedirect($this->listUrl())
            ->assertInertiaFlash('success', 'Groups deleted: 1. Skipped because they still have flows: 1.');

        $this->assertModelMissing($empty);
        $this->assertModelExists($busy);
        $this->assertModelExists($foreign);
    }

    public function test_deleting_only_groups_with_flows_is_an_error(): void
    {
        $busy = $this->group('Busy');
        FlowDraft::factory()->create(['assistant_id' => $this->assistant->getKey(), 'flow_group_id' => $busy->getKey()]);

        $this->actingAs($this->admin())
            ->delete($this->listUrl(), ['ids' => [$busy->getKey()]])
            ->assertInertiaFlash('error');

        $this->assertModelExists($busy);
    }

    public function test_deleting_many_validates_the_ids(): void
    {
        $this->actingAs($this->admin());

        $this->delete($this->listUrl(), [])->assertSessionHasErrors('ids');
        $this->delete($this->listUrl(), ['ids' => ['not-an-id']])->assertSessionHasErrors('ids.0');
        $this->delete($this->listUrl(), ['ids' => array_fill(0, 101, '00000000-0000-0000-0000-000000000002')])->assertSessionHasErrors('ids');
    }

    public function test_a_group_of_another_assistant_or_tenant_is_not_found(): void
    {
        $other   = $this->group('Other assistant', assistant: Assistant::factory()->create());
        $foreign = $this->group('Foreign', tenantId: self::OTHER_TENANT_ID, assistant: Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]));

        $this->actingAs($this->admin());

        foreach ([$other, $foreign] as $group) {
            $this->get($this->listUrl("/{$group->getKey()}/edit"))->assertNotFound();
            $this->put($this->listUrl("/{$group->getKey()}"), ['name' => 'Hijacked'])->assertNotFound();
            $this->delete($this->listUrl("/{$group->getKey()}"))->assertNotFound();
        }

        $this->get($this->listUrl('/not-an-id/edit'))->assertNotFound();
        $this->assertDatabaseMissing('flow_groups', ['name' => 'Hijacked']);
    }

    public function test_a_user_without_the_group_permission_is_refused_everywhere(): void
    {
        $group = $this->group('Hidden');

        $this->actingAs($this->userWith(Permission::ManageFlowDefinitions));

        $this->get($this->listUrl())->assertForbidden();
        $this->get($this->listUrl('/create'))->assertForbidden();
        $this->post($this->listUrl(), ['name' => 'New'])->assertForbidden();
        $this->post($this->listUrl('/inline'), ['name' => 'New'])->assertForbidden();
        $this->get($this->listUrl("/{$group->getKey()}/edit"))->assertForbidden();
        $this->put($this->listUrl("/{$group->getKey()}"), ['name' => 'Changed'])->assertForbidden();
        $this->delete($this->listUrl("/{$group->getKey()}"))->assertForbidden();
        $this->delete($this->listUrl(), ['ids' => [$group->getKey()]])->assertForbidden();

        $this->assertDatabaseHas('flow_groups', ['id' => $group->getKey(), 'name' => 'Hidden']);
    }

    public function test_a_manager_of_groups_may_write(): void
    {
        $this->actingAs($this->userWith(Permission::ManageFlowGroups))
            ->get($this->listUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can', ['create' => true, 'update' => true, 'delete' => true])
                ->etc());
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->get($this->listUrl())->assertRedirect(route('filament.admin.auth.login'));
    }

    private function listUrl(string $suffix = ''): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/flow-groups{$suffix}");
    }

    private function group(string $name, string $tenantId = self::TENANT_ID, ?Assistant $assistant = null): FlowGroup
    {
        return FlowGroup::query()->create([
            'tenant_id'    => $tenantId,
            'assistant_id' => ($assistant ?? $this->assistant)->getKey(),
            'name'         => $name,
        ]);
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
        // Opening an assistant's console needs ManageAssistants; the flow permission is what the screen asks on top.
        $user->givePermissionTo([Permission::ManageAssistants->value, $permission->value]);
        $user->assistants()->attach($this->assistant);

        return $user;
    }
}
