<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Media\Contracts\MediaUploaderInterface;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Exceptions\StorageLimitReachedException;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFileReference;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Media\Services\MediaFolderService;
use App\Domains\Media\Services\StorageLimitMessage;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Media\Enums\MediaKind;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeTenantLimits;

/**
 * The media library in the admin panel, served by the console shell in its admin mode, with the behaviour of the
 * Filament resource it replaces: files of the open folder, uploads under the size, type and storage limits, rename,
 * move, the trash, a file in use kept from permanent deletion, folders and their tree rules — all inside the tenant,
 * and with no storage path reaching the browser.
 */
final class AdminMediaConsoleTest extends InertiaConsoleTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $tenant = Tenant::query()->firstOrFail();
        $this->app->make(TenantContextInterface::class)->set($tenant);
        $this->tenantId = (string) $tenant->getKey();
    }

    public function test_the_list_shows_the_open_folder_inside_the_admin_shell(): void
    {
        $folder  = $this->folder('Docs');
        $atRoot  = $this->upload('root.txt');
        $inside  = $this->upload('inside.txt', $folder);
        $trashed = $this->upload('trashed.txt');
        $trashed->delete();
        $foreign = $this->foreignFile();

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Media/Index')
                ->where('navigation.mode', 'admin')
                ->where('urls.index', '/admin/media')
                ->where('can.manage', true)
                ->where('folder', null)
                ->where('table.rows', fn ($rows): bool => [$atRoot->id] === collect($rows)->pluck('id')->all())
                ->where('tree', fn ($tree): bool => [$folder->id] === collect($tree)->pluck('id')->all()
                    && 1 === collect($tree)->first()['files'])
                ->where('navigation.groups', fn ($groups): bool => collect($groups)
                    ->flatMap(static fn (array $group): array => $group['items'])
                    ->contains(static fn (array $item): bool => 'filament.admin.resources.media.index' === $item['key'] && false === $item['external']))
                ->etc());

        $this->actingAs($this->admin())
            ->get($this->url('?filter[folder]=' . $folder->id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('folder.id', $folder->id)
                ->where('breadcrumbs', [['id' => $folder->id, 'name' => 'Docs']])
                ->where('table.state.filters.folder', $folder->id)
                ->where('table.rows', fn ($rows): bool => [$inside->id] === collect($rows)->pluck('id')->all())
                ->etc());

        $this->actingAs($this->admin())
            ->get($this->url('?filter[trashed]=only'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows', fn ($rows): bool => [$trashed->id] === collect($rows)->pluck('id')->all()
                    && true === collect($rows)->first()['trashed'])
                ->etc());

        // Another tenant's folder is no folder: the root opens.
        $this->actingAs($this->admin())
            ->get($this->url('?filter[folder]=' . $foreign->folder_id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('folder', null)
                ->where('table.rows', fn ($rows): bool => ! collect($rows)->pluck('id')->contains($foreign->id))
                ->etc());
    }

    public function test_only_media_permissions_open_the_screen_and_only_manage_writes(): void
    {
        $file   = $this->upload('kept.txt');
        $folder = $this->folder('Kept');
        $denied = User::factory()->create();

        $this->actingAs($denied)->get($this->url())->assertForbidden();
        $this->actingAs($denied)->get($this->url("/{$file->id}"))->assertForbidden();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::ViewMedia->value);

        $this->actingAs($viewer)->get($this->url())->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.manage', false)->etc());
        $this->actingAs($viewer)->get($this->url("/{$file->id}"))->assertOk();

        foreach ([$denied, $viewer] as $user) {
            $this->actingAs($user)->post($this->url(), ['files' => [UploadedFile::fake()->createWithContent('x.txt', 'x')]])->assertForbidden();
            $this->actingAs($user)->patch($this->url("/{$file->id}"), ['name' => 'renamed.txt'])->assertForbidden();
            $this->actingAs($user)->put($this->url('/move'), ['ids' => [$file->id], 'folder_id' => $folder->id])->assertForbidden();
            $this->actingAs($user)->delete($this->url("/{$file->id}"))->assertForbidden();
            $this->actingAs($user)->delete($this->url(), ['ids' => [$file->id]])->assertForbidden();
            $this->actingAs($user)->post($this->url("/{$file->id}/restore"))->assertForbidden();
            $this->actingAs($user)->delete($this->url("/{$file->id}/force"))->assertForbidden();
            $this->actingAs($user)->post($this->url('/folders'), ['name' => 'New'])->assertForbidden();
            $this->actingAs($user)->put($this->url("/folders/{$folder->id}"), ['name' => 'Other'])->assertForbidden();
            $this->actingAs($user)->delete($this->url("/folders/{$folder->id}"))->assertForbidden();
        }

        $file->refresh();
        $this->assertSame('kept.txt', $file->name);
        $this->assertNull($file->folder_id);
        $this->assertFalse($file->trashed());
        $this->assertSame(1, MediaFile::query()->count());
        $this->assertSame(1, MediaFolder::query()->count());
    }

    public function test_a_visitor_is_sent_to_the_sign_in(): void
    {
        $this->get($this->url())->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_an_upload_stores_the_files_in_the_folder(): void
    {
        $folder = $this->folder('Uploads');

        $this->actingAs($this->admin())
            ->from($this->url())
            ->post($this->url(), [
                'files' => [
                    UploadedFile::fake()->createWithContent('one.txt', 'one-' . uniqid()),
                    UploadedFile::fake()->createWithContent('two.txt', 'two-' . uniqid()),
                ],
                'folder_id' => $folder->id,
            ])
            ->assertRedirect($this->url())
            ->assertInertiaFlash('success', __('media.notifications.uploaded', ['count' => 2]));

        $this->assertSame(['one.txt', 'two.txt'], MediaFile::query()->where('folder_id', $folder->id)->orderBy('name')->pluck('name')->all());
    }

    public function test_an_upload_outside_the_type_list_or_into_a_foreign_folder_stores_nothing(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post($this->url(), ['files' => [UploadedFile::fake()->create('tool.exe', 1, 'application/x-msdownload')]])
            ->assertSessionHasErrors('files.0');

        $this->actingAs($admin)
            ->post($this->url(), [
                'files'     => [UploadedFile::fake()->createWithContent('a.txt', 'a')],
                'folder_id' => $this->foreignFile()->folder_id,
            ])
            ->assertSessionHasErrors('folder_id');

        $this->assertSame(1, MediaFile::query()->count(), 'Only the foreign fixture exists.');
    }

    public function test_an_upload_over_the_storage_limit_keeps_what_fits_and_says_why(): void
    {
        $this->app->instance(TenantLimitsInterface::class, new FakeTenantLimits(['media_storage' => 10]));
        $this->app->forgetInstance(MediaUploaderInterface::class);

        $response = $this->actingAs($this->admin())
            ->from($this->url())
            ->post($this->url(), ['files' => [
                UploadedFile::fake()->createWithContent('one.txt', '123456'),
                UploadedFile::fake()->createWithContent('two.txt', 'abcdef'),
                UploadedFile::fake()->createWithContent('three.txt', 'ABCDEF'),
            ]]);

        $response->assertRedirect($this->url())
            ->assertInertiaFlash('error', StorageLimitMessage::for(new StorageLimitReachedException('media_storage', limit: 10, used: 6, incoming: 6))
                . ' ' . __('media.errors.storage_limit_saved', ['saved' => 1, 'total' => 3]));

        $this->assertSame(['one.txt'], MediaFile::query()->pluck('name')->all());
    }

    public function test_rename_move_trash_and_restore(): void
    {
        $admin  = $this->admin();
        $folder = $this->folder('Target');
        $one    = $this->upload('one.txt');
        $two    = $this->upload('two.txt');

        $this->actingAs($admin)->patch($this->url("/{$one->id}"), ['name' => 'first.txt'])
            ->assertInertiaFlash('success', trans('console.media.renamed'));
        $this->assertSame('first.txt', $one->fresh()?->name);

        $foreign = $this->foreignFile();
        $this->actingAs($admin)->put($this->url('/move'), ['ids' => [$one->id, $two->id, $foreign->id], 'folder_id' => $folder->id])
            ->assertInertiaFlash('success', __('media.notifications.files_moved', ['count' => 2]));
        $this->assertSame($folder->id, $one->fresh()?->folder_id);
        $this->assertSame($folder->id, $two->fresh()?->folder_id);
        $this->assertNotSame($folder->id, $foreign->fresh()?->folder_id);

        $this->actingAs($admin)->put($this->url('/move'), ['ids' => [$two->id], 'folder_id' => null]);
        $this->assertNull($two->fresh()?->folder_id);

        $this->actingAs($admin)->delete($this->url("/{$one->id}"))->assertInertiaFlash('success', trans('console.media.deleted'));
        $this->assertTrue(MediaFile::withTrashed()->findOrFail($one->id)->trashed());

        $this->actingAs($admin)->delete($this->url(), ['ids' => [$one->id, $two->id, $foreign->id]])
            ->assertInertiaFlash('success', trans('console.media.deleted_many', ['count' => 1]));
        $this->assertTrue(MediaFile::withTrashed()->findOrFail($two->id)->trashed());
        $this->assertFalse(MediaFile::withTrashed()->findOrFail($foreign->id)->trashed());

        $this->actingAs($admin)->post($this->url("/{$one->id}/restore"))->assertInertiaFlash('success', trans('console.media.restored'));
        $this->assertFalse(MediaFile::withTrashed()->findOrFail($one->id)->trashed());
    }

    public function test_a_file_in_use_is_not_deleted_permanently_and_an_unused_one_frees_its_bytes(): void
    {
        $admin = $this->admin();
        $used  = $this->upload('used.txt');
        MediaFileReference::query()->create([
            'media_file_id'  => $used->id,
            'reference_type' => MediaFileReference::TYPE_FLOW_DEFINITION,
            'reference_id'   => (string) Str::uuid(),
            'snapshot'       => ['flow_name' => 'Welcome'],
        ]);
        $used->delete();

        $this->actingAs($admin)->delete($this->url("/{$used->id}/force"))
            ->assertInertiaFlash('error', __('media.errors.has_references'));
        $this->assertNotNull(MediaFile::withTrashed()->find($used->id));

        $free = $this->upload('free.txt')->load('blob');
        $path = storage_path('app/' . $free->blob->storage_path);
        $free->delete();

        $this->actingAs($admin)->delete($this->url("/{$free->id}/force"))
            ->assertInertiaFlash('success', trans('console.media.force_deleted'));
        $this->assertNull(MediaFile::withTrashed()->find($free->id));
        $this->assertNull(MediaBlob::query()->find($free->blob_id));
        $this->assertFileDoesNotExist($path);
    }

    public function test_the_file_page_previews_through_a_signed_url_and_never_shows_the_storage_path(): void
    {
        $admin = $this->admin();
        $file  = $this->upload('photo.png', null, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true) ?: '')->load('blob');
        MediaFileReference::query()->create([
            'media_file_id'  => $file->id,
            'reference_type' => MediaFileReference::TYPE_FLOW_DEFINITION,
            'reference_id'   => (string) Str::uuid(),
            'snapshot'       => ['flow_name' => 'Welcome', 'node_label' => 'Send photo'],
        ]);

        $response = $this->actingAs($admin)->get($this->url("/{$file->id}"));

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Media/Show')
                ->where('file.name', 'photo.png')
                ->where('preview.type', 'image')
                ->where('preview.url', fn (string $url): bool => str_contains($url, "/media/files/{$file->id}/raw") && str_contains($url, 'signature='))
                ->where('references', [['id' => MediaFileReference::query()->value('id'), 'type' => __('media.references.types.flow_definition'), 'name' => 'Welcome', 'node' => 'Send photo']])
                ->etc());
        $this->assertStringNotContainsString($file->blob->storage_path, (string) $response->getContent());

        $file->delete();
        $this->actingAs($admin)->get($this->url("/{$file->id}"))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('preview', null)->where('file.trashed', true)->etc());

        $this->actingAs($admin)->get($this->url('/' . $this->foreignFile()->id))->assertNotFound();
        $this->actingAs($admin)->get($this->url('/not-an-id'))->assertNotFound();
        $this->actingAs($admin)->patch($this->url('/' . $this->foreignFile()->id), ['name' => 'x'])->assertNotFound();
    }

    public function test_folders_are_created_renamed_and_kept_within_the_depth_limit(): void
    {
        config(['media.folder.max_depth' => 2]);
        $admin = $this->admin();

        $this->actingAs($admin)->post($this->url('/folders'), ['name' => 'Top'])
            ->assertInertiaFlash('success', __('media.notifications.folder_created'));
        $top = MediaFolder::query()->where('name', 'Top')->firstOrFail();

        $this->actingAs($admin)->post($this->url('/folders'), ['name' => 'Child', 'parent_id' => $top->id]);
        $child = MediaFolder::query()->where('name', 'Child')->firstOrFail();
        $this->assertSame('/Top/Child', $child->path_cache);

        $this->actingAs($admin)->post($this->url('/folders'), ['name' => 'Grandchild', 'parent_id' => $child->id])
            ->assertSessionHasErrors(['parent_id' => __('media.errors.folder.too_deep', ['max' => 2])]);
        $this->actingAs($admin)->post($this->url('/folders'), ['name' => 'a/b'])->assertSessionHasErrors('name');

        $this->actingAs($admin)->put($this->url("/folders/{$top->id}"), ['name' => 'Renamed'])
            ->assertInertiaFlash('success', __('media.notifications.folder_renamed'));
        $this->assertSame('/Renamed/Child', $child->fresh()?->path_cache);

        $this->actingAs($admin)->put($this->url('/folders/' . $this->foreignFile()->folder_id), ['name' => 'Mine'])->assertNotFound();
    }

    public function test_deleting_a_folder_moves_its_contents_to_the_chosen_folder(): void
    {
        $admin   = $this->admin();
        $old     = $this->folder('Old');
        $sub     = $this->folder('Sub', $old);
        $deep    = $this->folder('Deep', $sub);
        $target  = $this->folder('Target');
        $file    = $this->upload('file.txt', $old);
        $trashed = $this->upload('trashed.txt', $old);
        $trashed->delete();

        // Not into the folder's own subtree.
        $this->actingAs($admin)->delete($this->url("/folders/{$old->id}"), ['move_to' => $deep->id])
            ->assertSessionHasErrors(['move_to' => __('media.errors.folder.own_subtree')]);
        $this->assertNotNull($old->fresh());

        $this->actingAs($admin)->delete($this->url("/folders/{$old->id}"), ['move_to' => $target->id, 'open_folder' => $sub->id])
            ->assertRedirect($this->url('?' . http_build_query(['filter' => ['folder' => $target->id]])))
            ->assertInertiaFlash('success', __('media.notifications.folder_deleted'));

        $this->assertNull(MediaFolder::query()->find($old->id));
        $this->assertSame($target->id, $file->fresh()?->folder_id);
        $this->assertSame($target->id, MediaFile::withTrashed()->findOrFail($trashed->id)->folder_id);
        $this->assertSame($target->id, $sub->fresh()?->parent_id);
        $this->assertSame('/Target/Sub', $sub->fresh()?->path_cache);
        $this->assertSame('/Target/Sub/Deep', $deep->fresh()?->path_cache);

        // Without a target, the contents go to the root; the list stays on the folder it had open elsewhere.
        $other = $this->folder('Other');
        $this->actingAs($admin)
            ->from($this->url('?filter[folder]=' . $other->id))
            ->delete($this->url("/folders/{$target->id}"), ['open_folder' => $other->id])
            ->assertRedirect($this->url('?filter[folder]=' . $other->id));
        $this->assertNull($file->fresh()?->folder_id);
        $this->assertSame('/Sub', $sub->fresh()?->path_cache);
    }

    public function test_a_batch_sent_one_file_per_request_reports_once_for_the_whole_batch(): void
    {
        $this->app->instance(TenantLimitsInterface::class, new FakeTenantLimits(['media_storage' => 10]));
        $this->app->forgetInstance(MediaUploaderInterface::class);
        $admin = $this->admin();

        // The first of three: stored, and nothing is said yet.
        $this->actingAs($admin)->from($this->url())
            ->post($this->url(), ['files' => [UploadedFile::fake()->createWithContent('one.txt', '123456')], 'batch_total' => 3, 'batch_saved' => 0])
            ->assertRedirect($this->url())
            ->assertInertiaFlashMissing('success')
            ->assertInertiaFlashMissing('error');

        // The second does not fit: the refusal speaks for the batch.
        $this->actingAs($admin)->from($this->url())
            ->post($this->url(), ['files' => [UploadedFile::fake()->createWithContent('two.txt', 'abcdef')], 'batch_total' => 3, 'batch_saved' => 1])
            ->assertInertiaFlash('error', StorageLimitMessage::for(new StorageLimitReachedException('media_storage', limit: 10, used: 6, incoming: 6))
                . ' ' . __('media.errors.storage_limit_saved', ['saved' => 1, 'total' => 3]));

        $this->assertSame(['one.txt'], MediaFile::query()->pluck('name')->all());
    }

    public function test_the_last_file_of_a_batch_reports_the_batch(): void
    {
        $this->actingAs($this->admin())->from($this->url())
            ->post($this->url(), ['files' => [UploadedFile::fake()->createWithContent('last.txt', 'last-' . uniqid())], 'batch_total' => 2, 'batch_saved' => 1])
            ->assertInertiaFlash('success', __('media.notifications.uploaded', ['count' => 2]));
    }

    public function test_the_extended_type_list_takes_phone_photos_and_still_refuses_svg(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post($this->url(), ['files' => [UploadedFile::fake()->create('photo.heic', 1, 'image/heic')]])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post($this->url(), ['files' => [UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml')]])
            ->assertSessionHasErrors('files.0');
        $this->actingAs($admin)->post($this->url(), ['files' => [UploadedFile::fake()->create('page.html', 1, 'text/html')]])
            ->assertSessionHasErrors('files.0');

        $this->assertSame(['photo.heic'], MediaFile::query()->pluck('name')->all());
    }

    public function test_a_filament_folder_link_opens_the_folder(): void
    {
        $folder = $this->folder('Bookmarked');

        $this->actingAs($this->admin())
            ->get($this->url('?folder=' . $folder->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('folder.id', $folder->id)->etc());
    }

    public function test_deleting_a_folder_moves_every_file_however_many(): void
    {
        $old    = $this->folder('Crowded');
        $target = $this->folder('Roomy');
        $blobId = $this->upload('seed.txt', $old)->blob_id;
        $now    = now();

        // Past the 1000-row page a chunked walk would use.
        foreach (array_chunk(range(1, 1100), 250) as $chunk) {
            MediaFile::query()->insert(array_map(fn (int $n): array => [
                'id'         => (string) Str::uuid(),
                'tenant_id'  => $this->tenantId,
                'blob_id'    => $blobId,
                'folder_id'  => $old->id,
                'name'       => "f{$n}.txt",
                'kind'       => MediaKind::Document->value,
                'metadata'   => '[]',
                'source'     => MediaSource::Upload->value,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        $this->actingAs($this->admin())->delete($this->url("/folders/{$old->id}"), ['move_to' => $target->id])->assertRedirect();

        $this->assertSame(1101, MediaFile::query()->where('folder_id', $target->id)->count());
        $this->assertSame(0, MediaFile::query()->whereNull('folder_id')->count());
    }

    private function url(string $suffix = ''): string
    {
        return $this->panelUrl("/admin/media{$suffix}");
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    private function folder(string $name, ?MediaFolder $parent = null): MediaFolder
    {
        return $this->app->make(MediaFolderService::class)->create($name, $parent);
    }

    private function upload(string $name, ?MediaFolder $folder = null, ?string $content = null): MediaFile
    {
        return $this->app->make(MediaUploaderInterface::class)->uploadFromUploadedFile(
            file: UploadedFile::fake()->createWithContent($name, $content ?? $name . '-' . uniqid()),
            folder: $folder,
            name: $name,
            source: MediaSource::Upload,
        );
    }

    /**
     * A file and its folder in another tenant, written directly: the screen must not reach either.
     */
    private function foreignFile(): MediaFile
    {
        $tenantId = (string) Str::uuid();
        $folder   = MediaFolder::query()->create(['tenant_id' => $tenantId, 'name' => 'Foreign', 'path_cache' => '/Foreign']);
        $blob     = MediaBlob::query()->create([
            'tenant_id'    => $tenantId,
            'content_hash' => hash('sha256', uniqid('', true)),
            'storage_path' => 'tenants/' . $tenantId . '/media/foreign.txt',
            'storage_disk' => 'local',
            'size'         => 7,
            'mime_type'    => 'text/plain',
        ]);

        return MediaFile::query()->create([
            'tenant_id' => $tenantId,
            'blob_id'   => $blob->id,
            'folder_id' => $folder->id,
            'name'      => 'foreign.txt',
            'kind'      => MediaKind::Document,
            'metadata'  => [],
            'source'    => MediaSource::Upload,
        ]);
    }
}
