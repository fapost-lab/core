<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Media;

use App\Domains\Media\Contracts\MediaUploaderInterface;
use App\Domains\Media\Exceptions\MediaFolderRuleException;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFileReference;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Media\Services\MediaFolderService;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Media\Enums\MediaKind;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeTenantLimits;

final class MediaApiTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->app->make(TenantContextInterface::class)->set(
            new RuntimeTenant(
                id: self::TENANT_ID,
                schemaName: 'main',
            )
        );
    }

    public function test_admin_can_create_folder_and_subfolder(): void
    {
        $this->actingAs($this->makeAdmin());

        $response = $this->postJson('/media/folders', ['name' => 'Projects']);
        $response->assertCreated();
        $rootId = $response->json('data.id');

        $sub = $this->postJson('/media/folders', ['name' => 'Onboarding', 'parent_id' => $rootId]);
        $sub->assertCreated();
        $sub->assertJsonPath('data.path_cache', '/Projects/Onboarding');
    }

    public function test_folder_move_into_own_subtree_is_rejected(): void
    {
        $this->actingAs($this->makeAdmin());

        $a = $this->postJson('/media/folders', ['name' => 'A'])->json('data.id');
        $b = $this->postJson('/media/folders', ['name' => 'B', 'parent_id' => $a])->json('data.id');

        $response = $this->patchJson("/media/folders/{$a}", ['parent_id' => $b]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['parent_id']);
    }

    public function test_folder_depth_beyond_limit_is_rejected(): void
    {
        $this->actingAs($this->makeAdmin());

        $parentId = null;
        for ($i = 1; $i <= 10; $i++) {
            $parentId = $this->postJson('/media/folders', ['name' => "L{$i}", 'parent_id' => $parentId])
                ->json('data.id');
        }

        $response = $this->postJson('/media/folders', ['name' => 'TooDeep', 'parent_id' => $parentId]);

        $response->assertUnprocessable();
    }

    public function test_moving_a_folder_leaves_a_same_named_sibling_subtree_alone(): void
    {
        $this->actingAs($this->makeAdmin());

        $a1   = $this->postJson('/media/folders', ['name' => 'A'])->json('data.id');
        $a2   = $this->postJson('/media/folders', ['name' => 'A'])->json('data.id');
        $sub1 = $this->postJson('/media/folders', ['name' => 'Sub', 'parent_id' => $a1])->json('data.id');
        $sub2 = $this->postJson('/media/folders', ['name' => 'Sub', 'parent_id' => $a2])->json('data.id');
        $leaf = $this->postJson('/media/folders', ['name' => 'Leaf', 'parent_id' => $sub2])->json('data.id');
        $dest = $this->postJson('/media/folders', ['name' => 'Dest'])->json('data.id');

        $this->patchJson("/media/folders/{$sub1}", ['parent_id' => $dest])->assertOk();

        $this->assertSame('/Dest/Sub', MediaFolder::query()->findOrFail($sub1)->path_cache);
        $this->assertSame('/A/Sub', MediaFolder::query()->findOrFail($sub2)->path_cache);
        $this->assertSame('/A/Sub/Leaf', MediaFolder::query()->findOrFail($leaf)->path_cache);

        $keep     = $this->postJson('/media/folders', ['name' => 'Sub', 'parent_id' => $a1])->json('data.id');
        $keepLeaf = $this->postJson('/media/folders', ['name' => 'Leaf', 'parent_id' => $keep])->json('data.id');

        $this->deleteJson("/media/folders/{$a1}?force=true")->assertNoContent();

        $this->assertSame('/Sub', MediaFolder::query()->findOrFail($keep)->path_cache);
        $this->assertSame('/Sub/Leaf', MediaFolder::query()->findOrFail($keepLeaf)->path_cache);
        $this->assertSame('/A/Sub', MediaFolder::query()->findOrFail($sub2)->path_cache);
        $this->assertSame('/A/Sub/Leaf', MediaFolder::query()->findOrFail($leaf)->path_cache);
    }

    public function test_delete_moving_contents_beyond_the_depth_limit_is_refused_by_subfolder_height(): void
    {
        $this->actingAs($this->makeAdmin());

        $parentId = null;
        for ($i = 1; $i <= 9; $i++) {
            $parentId = $this->postJson('/media/folders', ['name' => "L{$i}", 'parent_id' => $parentId])->json('data.id');
        }

        $doomed = $this->postJson('/media/folders', ['name' => 'Doomed'])->json('data.id');
        $child  = $this->postJson('/media/folders', ['name' => 'Child', 'parent_id' => $doomed])->json('data.id');
        $this->postJson('/media/folders', ['name' => 'Grand', 'parent_id' => $child])->assertCreated();

        $service = app(MediaFolderService::class);

        try {
            $service->deleteMovingContents($service->find($doomed), $service->find($parentId));
            $this->fail('The move should have been refused.');
        } catch (MediaFolderRuleException $exception) {
            $this->assertSame(MediaFolderRuleException::TOO_DEEP, $exception->reason);
        }

        $this->assertNotNull(MediaFolder::query()->find($doomed));
        $this->assertSame($doomed, MediaFolder::query()->findOrFail($child)->parent_id);
    }

    public function test_folder_tree_lists_each_folder_before_its_own_subtree_even_with_same_names(): void
    {
        $this->actingAs($this->makeAdmin());

        $a1 = $this->postJson('/media/folders', ['name' => 'A'])->json('data.id');
        $this->postJson('/media/folders', ['name' => 'A']);
        $this->postJson('/media/folders', ['name' => 'A B']);
        $x = $this->postJson('/media/folders', ['name' => 'X', 'parent_id' => $a1])->json('data.id');

        $ids  = array_column(app(MediaFolderService::class)->tree(), 'id');
        $rows = array_column(app(MediaFolderService::class)->tree(), null, 'id');

        $this->assertCount(4, $ids);
        $this->assertSame($x, $ids[array_search($a1, $ids, true) + 1]);
        $this->assertSame(1, $rows[$x]['depth']);
        $this->assertSame(0, $rows[$a1]['depth']);
    }

    public function test_renaming_a_folder_does_not_touch_folders_that_only_match_a_like_pattern(): void
    {
        $this->actingAs($this->makeAdmin());

        $under = $this->postJson('/media/folders', ['name' => 'a_b'])->json('data.id');
        $pct   = $this->postJson('/media/folders', ['name' => '50%'])->json('data.id');
        $pctC  = $this->postJson('/media/folders', ['name' => 'C', 'parent_id' => $pct])->json('data.id');
        $five  = $this->postJson('/media/folders', ['name' => '50x'])->json('data.id');
        $fiveC = $this->postJson('/media/folders', ['name' => 'C', 'parent_id' => $five])->json('data.id');
        $other = $this->postJson('/media/folders', ['name' => 'axb'])->json('data.id');
        $child = $this->postJson('/media/folders', ['name' => 'C', 'parent_id' => $under])->json('data.id');
        $odd   = $this->postJson('/media/folders', ['name' => 'C', 'parent_id' => $other])->json('data.id');

        $this->patchJson("/media/folders/{$under}", ['name' => 'z'])->assertOk();

        $this->assertSame('/z/C', MediaFolder::query()->findOrFail($child)->path_cache);
        $this->assertSame('/axb/C', MediaFolder::query()->findOrFail($odd)->path_cache);

        $this->patchJson("/media/folders/{$pct}", ['name' => 'half'])->assertOk();

        $this->assertSame('/half/C', MediaFolder::query()->findOrFail($pctC)->path_cache);
        $this->assertSame('/50x/C', MediaFolder::query()->findOrFail($fiveC)->path_cache);
    }

    public function test_moving_a_deep_subtree_beyond_the_depth_limit_is_rejected(): void
    {
        $this->actingAs($this->makeAdmin());

        $parentId = null;
        for ($i = 1; $i <= 9; $i++) {
            $parentId = $this->postJson('/media/folders', ['name' => "L{$i}", 'parent_id' => $parentId])->json('data.id');
        }

        $top = $this->postJson('/media/folders', ['name' => 'Top'])->json('data.id');
        $mid = $this->postJson('/media/folders', ['name' => 'Mid', 'parent_id' => $top])->json('data.id');
        $this->postJson('/media/folders', ['name' => 'Bottom', 'parent_id' => $mid])->assertCreated();

        // L9 is at depth 9; Top has height 3, so it would reach depth 12.
        $this->patchJson("/media/folders/{$top}", ['parent_id' => $parentId])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
        $this->assertNull(MediaFolder::query()->findOrFail($top)->parent_id);

        // Mid has height 2, so it would reach depth 11.
        $this->patchJson("/media/folders/{$mid}", ['parent_id' => $parentId])->assertUnprocessable();

        // A single folder still fits at depth 10.
        $leaf = $this->postJson('/media/folders', ['name' => 'Single'])->json('data.id');
        $this->patchJson("/media/folders/{$leaf}", ['parent_id' => $parentId])->assertOk();
    }

    public function test_move_judges_the_parent_by_the_tree_as_it_is_now_not_by_the_model_it_was_given(): void
    {
        $a = MediaFolder::query()->create(['tenant_id' => self::TENANT_ID, 'name' => 'A', 'path_cache' => '/A']);
        $b = MediaFolder::query()->create(['tenant_id' => self::TENANT_ID, 'name' => 'B', 'path_cache' => '/B']);

        // B is read before a concurrent move of B into A commits; the stale model still shows it at the root.
        $staleB = MediaFolder::query()->findOrFail($b->id);
        MediaFolder::query()->whereKey($b->id)->update(['parent_id' => $a->id]);

        $this->expectException(MediaFolderRuleException::class);

        try {
            $this->app->make(MediaFolderService::class)->move($a, $staleB);
        } finally {
            $this->assertNull(MediaFolder::query()->findOrFail($a->id)->parent_id);
        }
    }

    public function test_move_of_a_stale_folder_model_leaves_parent_and_path_consistent(): void
    {
        $service = $this->app->make(MediaFolderService::class);
        $a       = MediaFolder::query()->create(['tenant_id' => self::TENANT_ID, 'name' => 'A', 'path_cache' => '/A']);
        $b       = MediaFolder::query()->create(['tenant_id' => self::TENANT_ID, 'name' => 'B', 'path_cache' => '/B']);
        $x       = MediaFolder::query()->create(['tenant_id' => self::TENANT_ID, 'name' => 'X', 'path_cache' => '/X']);

        // X is loaded at the root, then another request moves it under A; ours asks for B.
        $stale = MediaFolder::query()->findOrFail($x->id);
        $service->move($x, $a);

        $service->move($stale, $b);

        $moved = MediaFolder::query()->findOrFail($x->id);
        $this->assertSame($b->id, $moved->parent_id);
        $this->assertSame('/B/X', $moved->path_cache);
    }

    public function test_move_of_a_stale_folder_model_to_the_parent_it_still_shows_is_written(): void
    {
        $service = $this->app->make(MediaFolderService::class);
        $a       = MediaFolder::query()->create(['tenant_id' => self::TENANT_ID, 'name' => 'A', 'path_cache' => '/A']);
        $x       = MediaFolder::query()->create(['tenant_id' => self::TENANT_ID, 'name' => 'X', 'path_cache' => '/X']);

        // X is loaded at the root, then another request moves it under A; ours asks for the root again, the very
        // value the stale model holds, so only a re-read of the folder makes the write happen.
        $stale = MediaFolder::query()->findOrFail($x->id);
        $service->move($x, $a);

        $service->move($stale, null);

        $moved = MediaFolder::query()->findOrFail($x->id);
        $this->assertNull($moved->parent_id);
        $this->assertSame('/X', $moved->path_cache);
    }

    public function test_move_into_a_parent_deleted_since_it_was_loaded_is_a_field_error(): void
    {
        $a = MediaFolder::query()->create(['tenant_id' => self::TENANT_ID, 'name' => 'A', 'path_cache' => '/A']);
        $b = MediaFolder::query()->create(['tenant_id' => self::TENANT_ID, 'name' => 'B', 'path_cache' => '/B']);
        MediaFolder::query()->whereKey($b->id)->delete();

        try {
            $this->app->make(MediaFolderService::class)->move($a, $b);
            $this->fail('A deleted parent must be refused.');
        } catch (MediaFolderRuleException $e) {
            $this->assertSame('parent_id', $e->attribute);
        }
    }

    public function test_create_under_a_stale_parent_moved_to_the_depth_limit_is_a_field_error(): void
    {
        $service  = $this->app->make(MediaFolderService::class);
        $parentId = null;
        $path     = '';
        for ($i = 1; $i <= 9; $i++) {
            $path .= "/L{$i}";
            $parentId = MediaFolder::query()->create(['tenant_id' => self::TENANT_ID, 'name' => "L{$i}", 'parent_id' => $parentId, 'path_cache' => $path])->id;
        }
        $p = MediaFolder::query()->create(['tenant_id' => self::TENANT_ID, 'name' => 'P', 'path_cache' => '/P']);

        // P is loaded at the root, then another request moves it under L9, to depth 10: there is no room under it now.
        $stale = MediaFolder::query()->findOrFail($p->id);
        $service->move($p, MediaFolder::query()->findOrFail($parentId));

        try {
            $service->create('Child', $stale);
            $this->fail('A parent at the depth limit must be refused, however it was loaded.');
        } catch (MediaFolderRuleException $e) {
            $this->assertSame('parent_id', $e->attribute);
            $this->assertSame(MediaFolderRuleException::TOO_DEEP, $e->reason);
        }

        $this->assertSame(0, MediaFolder::query()->where('parent_id', $p->id)->count());
    }

    public function test_rename_of_a_stale_folder_model_builds_the_path_from_its_parent_now(): void
    {
        $service = $this->app->make(MediaFolderService::class);
        $a       = MediaFolder::query()->create(['tenant_id' => self::TENANT_ID, 'name' => 'A', 'path_cache' => '/A']);
        $x       = MediaFolder::query()->create(['tenant_id' => self::TENANT_ID, 'name' => 'X', 'path_cache' => '/X']);

        // X is loaded at the root, then another request moves it under A; ours renames it.
        $stale = MediaFolder::query()->findOrFail($x->id);
        $service->move($x, $a);

        $service->rename($stale, 'Y');

        $renamed = MediaFolder::query()->findOrFail($x->id);
        $this->assertSame($a->id, $renamed->parent_id);
        $this->assertSame('/A/Y', $renamed->path_cache);
    }

    public function test_rename_of_a_folder_deleted_since_it_was_loaded_is_not_found(): void
    {
        $x = MediaFolder::query()->create(['tenant_id' => self::TENANT_ID, 'name' => 'X', 'path_cache' => '/X']);
        MediaFolder::query()->whereKey($x->id)->delete();

        $this->expectException(ModelNotFoundException::class);

        $this->app->make(MediaFolderService::class)->rename($x, 'Y');
    }

    public function test_breadcrumbs_run_from_the_root_down_through_three_levels(): void
    {
        $a = MediaFolder::query()->create(['tenant_id' => self::TENANT_ID, 'name' => 'A', 'path_cache' => '/A']);
        $b = MediaFolder::query()->create(['tenant_id' => self::TENANT_ID, 'name' => 'B', 'parent_id' => $a->id, 'path_cache' => '/A/B']);
        $c = MediaFolder::query()->create(['tenant_id' => self::TENANT_ID, 'name' => 'C', 'parent_id' => $b->id, 'path_cache' => '/A/B/C']);

        $this->assertSame(
            [['id' => $a->id, 'name' => 'A'], ['id' => $b->id, 'name' => 'B'], ['id' => $c->id, 'name' => 'C']],
            $this->app->make(MediaFolderService::class)->breadcrumbs($c),
        );
    }

    public function test_non_empty_folder_delete_requires_force(): void
    {
        $this->actingAs($this->makeAdmin());

        $folder = MediaFolder::query()->create([
            'tenant_id'  => self::TENANT_ID,
            'name'       => 'NotEmpty',
            'path_cache' => '/NotEmpty',
        ]);
        $blob = $this->makeBlob();
        MediaFile::query()->create([
            'tenant_id' => self::TENANT_ID,
            'blob_id'   => $blob->id,
            'folder_id' => $folder->id,
            'name'      => 'a.bin',
            'kind'      => MediaKind::Document,
            'metadata'  => [],
            'source'    => 'upload',
        ]);

        $this->deleteJson("/media/folders/{$folder->id}")->assertStatus(409);
        $this->deleteJson("/media/folders/{$folder->id}?force=true")->assertNoContent();
    }

    public function test_forced_folder_delete_moves_nested_subfolders_to_the_root_with_their_paths(): void
    {
        $this->actingAs($this->makeAdmin());

        $top   = $this->postJson('/media/folders', ['name' => 'Top'])->json('data.id');
        $child = $this->postJson('/media/folders', ['name' => 'Child', 'parent_id' => $top])->json('data.id');
        $leaf  = $this->postJson('/media/folders', ['name' => 'Leaf', 'parent_id' => $child])->json('data.id');

        $this->deleteJson("/media/folders/{$top}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Folder is not empty. Pass ?force=true to delete it and move its files and subfolders to the root.');
        $this->deleteJson("/media/folders/{$top}?force=true")->assertNoContent();

        $this->assertNull(MediaFolder::query()->find($top));
        $childFolder = MediaFolder::query()->findOrFail($child);
        $this->assertNull($childFolder->parent_id);
        $this->assertSame('/Child', $childFolder->path_cache);
        $this->assertSame($child, MediaFolder::query()->findOrFail($leaf)->parent_id);
        $this->assertSame('/Child/Leaf', MediaFolder::query()->findOrFail($leaf)->path_cache);
    }

    public function test_upload_creates_file_blob_and_returns_signed_url(): void
    {
        $this->actingAs($this->makeAdmin());

        $upload = UploadedFile::fake()->createWithContent('hello.txt', 'hello world');

        $response = $this->postJson('/media/files', [
            'file' => $upload,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'hello.txt');
        $response->assertJsonPath('data.kind', MediaKind::Document->value);
        $response->assertJsonPath('data.deduplicated', false);
        $this->assertNotNull($response->json('data.preview_url'));
        $response->assertJsonStructure(['provider_warnings']);
    }

    public function test_duplicate_upload_returns_dedup_flag(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->postJson('/media/files', [
            'file' => UploadedFile::fake()->createWithContent('first.txt', 'same content'),
        ])->assertCreated();

        $second = $this->postJson('/media/files', [
            'file' => UploadedFile::fake()->createWithContent('second.txt', 'same content'),
        ]);

        $second->assertCreated();
        $second->assertJsonPath('data.deduplicated', true);
        $this->assertSame(1, MediaBlob::query()->count());
        $this->assertSame(2, MediaFile::query()->count());
    }

    public function test_upload_rejects_disallowed_mime_type(): void
    {
        $this->actingAs($this->makeAdmin());

        $upload = UploadedFile::fake()->createWithContent('payload.exe', 'MZ');

        $response = $this->postJson('/media/files', ['file' => $upload]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['file']);
    }

    public function test_provider_warning_emitted_for_oversized_image(): void
    {
        $this->actingAs($this->makeAdmin());

        // 11 MB > Telegram image limit (10 MB). The size is measured from the bytes, so pad a real JPEG.
        $small  = UploadedFile::fake()->image('small.jpg', 100, 100);
        $jpeg   = (string) file_get_contents($small->getRealPath());
        $upload = UploadedFile::fake()->createWithContent('big.jpg', $jpeg . str_repeat("\0", 11 * 1024 * 1024));

        $response = $this->postJson('/media/files', ['file' => $upload]);

        $response->assertCreated();
        $warnings = $response->json('provider_warnings');
        $this->assertNotEmpty($warnings, 'Expected provider_warnings to be populated.');
        $this->assertSame('telegram', $warnings[0]['channel_type']);
        $this->assertSame('image', $warnings[0]['kind']);
    }

    public function test_force_delete_with_references_requires_force_flag(): void
    {
        $this->actingAs($this->makeAdmin());

        $blob = $this->makeBlob();
        $file = MediaFile::query()->create([
            'tenant_id' => self::TENANT_ID,
            'blob_id'   => $blob->id,
            'name'      => 'used.bin',
            'kind'      => MediaKind::Document,
            'metadata'  => [],
            'source'    => 'upload',
        ]);

        MediaFileReference::query()->create([
            'media_file_id'  => $file->id,
            'reference_type' => MediaFileReference::TYPE_FLOW_DEFINITION,
            'reference_id'   => '00000000-0000-0000-0000-0000000000aa',
            'snapshot'       => ['flow_name' => 'Demo'],
        ]);

        $this->deleteJson("/media/files/{$file->id}/force")->assertStatus(409)
            ->assertJsonPath('references', 1);

        $this->deleteJson("/media/files/{$file->id}/force?force=true")->assertNoContent();
        $this->assertNull(MediaFile::withTrashed()->find($file->id));
    }

    public function test_upload_over_the_storage_limit_is_refused_with_the_numbers(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->app->instance(TenantLimitsInterface::class, new FakeTenantLimits(['media_storage' => 5]));
        // The node handler registry builds an uploader at boot, with the default limits.
        $this->app->forgetInstance(MediaUploaderInterface::class);

        $response = $this->postJson('/media/files', [
            'file' => UploadedFile::fake()->createWithContent('big.txt', 'twelve bytes'),
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error', 'storage_limit_reached')
            ->assertJsonPath('limit', 5)
            ->assertJsonPath('used', 0)
            ->assertJsonPath('needed', 12);
        $this->assertStringContainsString('Media storage is full', (string) $response->json('message'));
        $this->assertSame(0, MediaBlob::query()->count());
        $this->assertSame(0, MediaFile::query()->count());
    }

    public function test_force_delete_frees_the_blob_and_the_stored_object(): void
    {
        $this->actingAs($this->makeAdmin());

        $fileId = $this->postJson('/media/files', [
            'file' => UploadedFile::fake()->createWithContent('free-me.txt', 'free-me-' . uniqid()),
        ])->assertCreated()->json('data.id');

        $blob = MediaBlob::query()->firstOrFail();
        $path = storage_path('app/' . $blob->storage_path);
        $this->assertFileExists($path);

        $this->deleteJson("/media/files/{$fileId}")->assertNoContent();
        $this->deleteJson("/media/files/{$fileId}/force")->assertNoContent();

        $this->assertSame(0, MediaBlob::query()->count());
        $this->assertFileDoesNotExist($path);
    }

    public function test_force_delete_keeps_a_blob_another_file_still_uses(): void
    {
        $this->actingAs($this->makeAdmin());
        $content = 'shared-' . uniqid();

        $first  = $this->postJson('/media/files', ['file' => UploadedFile::fake()->createWithContent('a.txt', $content)])->json('data.id');
        $second = $this->postJson('/media/files', ['file' => UploadedFile::fake()->createWithContent('b.txt', $content)])->json('data.id');

        $blob = MediaBlob::query()->firstOrFail();
        $path = storage_path('app/' . $blob->storage_path);

        // The second file sits in the trash: it still holds the blob.
        $this->deleteJson("/media/files/{$second}")->assertNoContent();
        $this->deleteJson("/media/files/{$first}")->assertNoContent();
        $this->deleteJson("/media/files/{$first}/force")->assertNoContent();

        $this->assertSame(1, MediaBlob::query()->count());
        $this->assertFileExists($path);

        $this->deleteJson("/media/files/{$second}/force")->assertNoContent();

        $this->assertSame(0, MediaBlob::query()->count());
        $this->assertFileDoesNotExist($path);
    }

    public function test_references_endpoint_lists_snapshots(): void
    {
        $this->actingAs($this->makeAdmin());

        $blob = $this->makeBlob();
        $file = MediaFile::query()->create([
            'tenant_id' => self::TENANT_ID,
            'blob_id'   => $blob->id,
            'name'      => 'doc.pdf',
            'kind'      => MediaKind::Document,
            'metadata'  => [],
            'source'    => 'upload',
        ]);

        MediaFileReference::query()->create([
            'media_file_id'  => $file->id,
            'reference_type' => MediaFileReference::TYPE_FLOW_DEFINITION,
            'reference_id'   => '00000000-0000-0000-0000-0000000000bb',
            'snapshot'       => ['flow_name' => 'Demo flow', 'node_id' => 'n1'],
        ]);

        $response = $this->getJson("/media/files/{$file->id}/references");
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.snapshot.flow_name', 'Demo flow');
    }

    public function test_picker_contents_returns_subfolders_and_filtered_files(): void
    {
        $this->actingAs($this->makeAdmin());

        $folder = MediaFolder::query()->create([
            'tenant_id'  => self::TENANT_ID,
            'name'       => 'Mixed',
            'path_cache' => '/Mixed',
        ]);

        $imgBlob = $this->makeBlob('hash-image', 'image/jpeg');
        $docBlob = $this->makeBlob('hash-doc', 'application/pdf');

        MediaFile::query()->create([
            'tenant_id' => self::TENANT_ID,
            'blob_id'   => $imgBlob->id,
            'folder_id' => $folder->id,
            'name'      => 'pic.jpg',
            'kind'      => MediaKind::Image,
            'metadata'  => [],
            'source'    => 'upload',
        ]);
        MediaFile::query()->create([
            'tenant_id' => self::TENANT_ID,
            'blob_id'   => $docBlob->id,
            'folder_id' => $folder->id,
            'name'      => 'doc.pdf',
            'kind'      => MediaKind::Document,
            'metadata'  => [],
            'source'    => 'upload',
        ]);

        $response = $this->getJson("/media/picker/contents?folder_id={$folder->id}&kind=image");
        $response->assertOk();
        $response->assertJsonPath('current_folder.id', $folder->id);
        $response->assertJsonCount(1, 'files');
        $response->assertJsonPath('files.0.name', 'pic.jpg');
    }

    public function test_user_without_view_media_permission_is_denied(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->getJson('/media/folders')->assertForbidden();
    }

    public function test_view_only_user_cannot_create(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ViewMedia->value);
        $this->actingAs($user);

        $this->getJson('/media/folders')->assertOk();
        $this->postJson('/media/folders', ['name' => 'X'])->assertForbidden();
    }

    public function test_signed_raw_url_streams_file(): void
    {
        $this->actingAs($this->makeAdmin());

        $upload     = UploadedFile::fake()->createWithContent('download.txt', 'streaming bytes');
        $created    = $this->postJson('/media/files', ['file' => $upload])->assertCreated();
        $previewUrl = $created->json('data.preview_url');
        $this->assertIsString($previewUrl);

        // Strip host so we can hit it as a relative path through the test client.
        $relative = parse_url($previewUrl, PHP_URL_PATH) . '?' . parse_url($previewUrl, PHP_URL_QUERY);

        $this->get($relative)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_unsigned_raw_url_is_rejected(): void
    {
        $this->actingAs($this->makeAdmin());

        $upload  = UploadedFile::fake()->createWithContent('plain.txt', 'plain');
        $created = $this->postJson('/media/files', ['file' => $upload])->assertCreated();
        $fileId  = $created->json('data.id');

        $this->get("/media/files/{$fileId}/raw")->assertForbidden();
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    private function makeBlob(
        string $contentHashSeed = 'demo',
        string $mimeType = 'application/octet-stream'
    ): MediaBlob {
        return MediaBlob::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'content_hash' => hash('sha256', $contentHashSeed),
            'storage_path' => 'tenants/test/media/' . hash('crc32', $contentHashSeed) . '.bin',
            'storage_disk' => 'local',
            'size'         => 100,
            'mime_type'    => $mimeType,
        ]);
    }
}
