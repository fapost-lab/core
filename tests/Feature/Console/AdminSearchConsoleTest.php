<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Http\Shell\AdminSearch;
use App\Http\Shell\RouteOwnership;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Media\Enums\MediaKind;
use Inertia\Testing\AssertableInertia;

/**
 * The admin shell's search palette, in place of Filament's global search: what each user may find, inside the tenant,
 * a few hits per group, links to the screens that open them, and no storage path.
 */
final class AdminSearchConsoleTest extends InertiaConsoleTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string OTHER_TENANT_ID = '00000000-0000-0000-0000-0000000000ff';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_an_administrator_finds_every_kind_with_links_to_its_screen(): void
    {
        $assistant = Assistant::factory()->create(['name' => 'Acme support']);
        Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID, 'name' => 'Acme foreign']);
        $user = User::factory()->create(['name' => 'Anna Acme', 'email' => 'anna@example.test']);
        $role = Role::query()->create(['name' => 'acme_editor', 'display_name' => 'Acme editor', 'guard_name' => 'web', 'priority' => 10, 'is_system' => false]);
        $file = $this->mediaFile('acme-logo.png', self::TENANT_ID);
        $this->mediaFile('acme-foreign.png', self::OTHER_TENANT_ID);

        $this->actingAs($this->admin())
            ->getJson($this->url('acme'))
            ->assertOk()
            ->assertExactJson(['groups' => [
                ['key' => 'assistants', 'items' => [[
                    'id'       => (string) $assistant->getKey(),
                    'title'    => 'Acme support',
                    'subtitle' => null,
                    'url'      => "/admin/assistants/{$assistant->getKey()}",
                    'external' => false,
                ]]],
                ['key' => 'users', 'items' => [[
                    'id'       => (string) $user->getKey(),
                    'title'    => 'Anna Acme',
                    'subtitle' => 'anna@example.test',
                    'url'      => "/admin/users/{$user->getKey()}/edit",
                    'external' => false,
                ]]],
                ['key' => 'roles', 'items' => [[
                    'id'       => (string) $role->getKey(),
                    'title'    => 'Acme editor',
                    'subtitle' => 'acme_editor',
                    'url'      => "/admin/roles/{$role->getKey()}/edit",
                    'external' => false,
                ]]],
                // No storage path. `external` follows whoever answers the media screen's name (Filament until it moves).
                ['key' => 'media', 'items' => [[
                    'id'       => (string) $file->getKey(),
                    'title'    => 'acme-logo.png',
                    'subtitle' => 'Image',
                    'url'      => "/admin/media/{$file->getKey()}",
                    'external' => ! $this->app->make(RouteOwnership::class)->isMigrated('filament.admin.resources.media.view'),
                ]]],
            ]]);
    }

    public function test_the_search_matches_case_insensitively_and_takes_like_wildcards_literally(): void
    {
        User::factory()->create(['name' => 'Bob', 'email' => 'BOB@Example.test']);
        User::factory()->create(['name' => 'Carol', 'email' => 'carol@example.test']);

        $this->actingAs($this->admin());

        $this->getJson($this->url('bob@ex'))->assertJsonPath('groups.0.items.0.title', 'Bob')->assertJsonCount(1, 'groups.0.items');
        $this->getJson($this->url('%'))->assertExactJson(['groups' => []]);
        $this->getJson($this->url('b_b'))->assertExactJson(['groups' => []]);
    }

    public function test_a_non_administrator_finds_only_their_assistants_and_nothing_they_may_not_list(): void
    {
        $mine = Assistant::factory()->create(['name' => 'Acme mine']);
        Assistant::factory()->create(['name' => 'Acme theirs']);
        User::factory()->create(['name' => 'Acme person']);
        $this->mediaFile('acme.png', self::TENANT_ID);
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);
        $user->assistants()->attach($mine);

        $this->actingAs($user)
            ->getJson($this->url('acme'))
            ->assertOk()
            ->assertJsonCount(1, 'groups')
            ->assertJsonPath('groups.0.key', 'assistants')
            ->assertJsonCount(1, 'groups.0.items')
            ->assertJsonPath('groups.0.items.0.id', (string) $mine->getKey());
    }

    public function test_a_user_the_actor_may_not_change_opens_on_the_list_narrowed_to_them(): void
    {
        $target = User::factory()->create(['name' => 'Dora', 'email' => 'dora@example.test']);
        $target->assignRole(RoleEnum::Admin->value);
        $manager = User::factory()->create();
        $manager->givePermissionTo(Permission::ManageUsers->value);

        $this->actingAs($manager)
            ->getJson($this->url('dora'))
            ->assertJsonPath('groups.0.key', 'users')
            ->assertJsonPath('groups.0.items.0.url', '/admin/users?search=dora%40example.test');
    }

    public function test_each_group_is_short_and_a_short_text_finds_nothing(): void
    {
        foreach (range(1, AdminSearch::LIMIT + 2) as $i) {
            Assistant::factory()->create(['name' => "Zeta {$i}"]);
        }

        $this->actingAs($this->admin());

        $this->getJson($this->url('zeta'))->assertJsonCount(AdminSearch::LIMIT, 'groups.0.items');
        $this->getJson($this->url('z'))->assertExactJson(['groups' => []]);
        $this->getJson($this->url(str_repeat('z', 101)))->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    public function test_a_user_with_nothing_to_search_is_refused_and_gets_no_palette(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson($this->url('acme'))->assertForbidden();

        $this->get($this->panelUrl('/admin'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('navigation.searchUrl', null)->etc());
    }

    public function test_the_admin_shell_offers_the_palette_and_an_assistant_console_does_not(): void
    {
        $assistant = Assistant::factory()->create();

        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('navigation.searchUrl', '/admin/search')->etc());

        $this->get($this->panelUrl("/assistant/{$assistant->getKey()}/dashboard"))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('navigation.searchUrl', null)->etc());
    }

    public function test_the_search_is_throttled(): void
    {
        $this->actingAs($this->admin());

        foreach (range(1, 60) as $ignored) {
            $this->getJson($this->url('acme'))->assertOk();
        }

        $this->getJson($this->url('acme'))->assertTooManyRequests();
    }

    public function test_a_guest_is_not_answered(): void
    {
        $this->getJson($this->url('acme'))->assertUnauthorized();
    }

    private function mediaFile(string $name, string $tenantId): MediaFile
    {
        $blob = MediaBlob::query()->create([
            'tenant_id'    => $tenantId,
            'content_hash' => hash('sha256', $tenantId . $name),
            'storage_path' => "tenants/test/media/{$name}",
            'storage_disk' => 'local',
            'size'         => 10,
            'mime_type'    => 'image/png',
        ]);

        return MediaFile::query()->create([
            'tenant_id' => $tenantId,
            'blob_id'   => $blob->id,
            'name'      => $name,
            'kind'      => MediaKind::Image,
            'metadata'  => [],
            'source'    => MediaSource::Upload,
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    private function url(string $text): string
    {
        return $this->panelUrl('/admin/search?' . http_build_query(['q' => $text]));
    }
}
