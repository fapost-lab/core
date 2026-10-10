<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Media;

use App\Domains\Media\Contracts\MediaUploaderInterface;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFileReference;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Filament\Resources\Media\MediaResource;
use App\Filament\Resources\Media\Pages\ListMedia;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Media\Enums\MediaKind;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeTenantLimits;

final class MediaResourceTest extends FeatureTestCase
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

    public function test_user_with_view_permission_can_load_media_index(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ViewMedia->value);
        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get($this->panelUrl('/admin/media'))->assertOk();
    }

    public function test_user_without_media_permission_cannot_load_media_index(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get($this->panelUrl('/admin/media'))->assertForbidden();
    }

    public function test_navigation_visibility_follows_permission(): void
    {
        $allowed = User::factory()->create();
        $allowed->givePermissionTo(Permission::ViewMedia->value);
        $this->actingAs($allowed);
        $this->assertTrue(MediaResource::canViewAny());

        $denied = User::factory()->create();
        $this->actingAs($denied);
        $this->assertFalse(MediaResource::canViewAny());
    }

    public function test_force_delete_blocked_when_references_exist(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);
        $this->actingAs($admin);

        $upload = UploadedFile::fake()->createWithContent('boom.txt', 'data');
        $file   = $this->app->make(MediaUploaderInterface::class)->uploadFromUploadedFile(
            file: $upload,
            folder: null,
            name: 'boom.txt',
            source: MediaSource::Upload,
        );

        MediaFileReference::query()->create([
            'media_file_id'  => $file->id,
            'reference_type' => MediaFileReference::TYPE_FLOW_DEFINITION,
            'reference_id'   => '00000000-0000-0000-0000-0000000000ff',
            'snapshot'       => ['flow_name' => 'Demo'],
        ]);

        // Soft delete first so ForceDelete becomes available.
        $file->delete();

        $this->assertTrue($file->refresh()->trashed());
        $this->assertSame(1, MediaFileReference::query()->where('media_file_id', $file->id)->count());
    }

    public function test_upload_stops_at_the_storage_limit_keeps_what_was_saved_and_tells_the_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->app->instance(TenantLimitsInterface::class, new FakeTenantLimits(['media_storage' => 10]));
        $this->app->forgetInstance(MediaUploaderInterface::class);

        $directory = 'media-limit-test-' . uniqid();
        $paths     = [];

        foreach (['one' => '123456', 'two' => 'abcdef', 'three' => 'ABCDEF'] as $name => $content) {
            $paths[] = $directory . '/' . $name . '-' . uniqid() . '.txt';
            Storage::disk('local')->put(end($paths), $content);
        }

        try {
            Livewire::actingAs($admin)
                ->test(ListMedia::class)
                ->callAction('upload', ['files' => $paths])
                ->assertNotified(__('media.errors.storage_limit_title'));

            $this->assertSame(1, MediaFile::query()->count(), 'The first file fits; the second is refused and ends the batch.');

            foreach ($paths as $path) {
                $this->assertFalse(Storage::disk('local')->exists($path), 'Temporary uploads are removed.');
            }
        } finally {
            Storage::disk('local')->deleteDirectory($directory);
        }
    }

    public function test_force_delete_action_frees_the_blob_and_the_stored_object(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $file = $this->app->make(MediaUploaderInterface::class)->uploadFromUploadedFile(
            file: UploadedFile::fake()->createWithContent('gone.txt', 'gone-' . uniqid()),
            folder: null,
            name: 'gone.txt',
            source: MediaSource::Upload,
        )->load('blob');
        $path = storage_path('app/' . $file->blob->storage_path);
        $file->delete();

        Livewire::actingAs($admin)
            ->test(ListMedia::class)
            ->callTableAction('forceDelete', $file);

        $this->assertNull(MediaFile::withTrashed()->find($file->id));
        $this->assertNull(MediaBlob::query()->find($file->blob_id));
        $this->assertFileDoesNotExist($path);
    }

    public function test_signed_url_resolves_to_existing_file(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);
        $this->actingAs($admin);

        $blob = MediaBlob::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'content_hash' => str_repeat('c', 64),
            'storage_path' => 'tenants/' . self::TENANT_ID . '/media/sample.txt',
            'storage_disk' => 'local',
            'size'         => 5,
            'mime_type'    => 'text/plain',
        ]);

        $file = MediaFile::query()->create([
            'tenant_id' => self::TENANT_ID,
            'blob_id'   => $blob->id,
            'name'      => 'sample.txt',
            'kind'      => MediaKind::Document,
            'metadata'  => [],
            'source'    => MediaSource::Upload,
        ]);

        $service = $this->app->make(\App\Domains\Media\Contracts\MediaServiceInterface::class);
        $url     = $service->signedUrl($file);

        $this->assertIsString($url);
        $this->assertStringContainsString("/media/files/{$file->id}/raw", $url);
        $this->assertStringContainsString('signature=', $url);
    }
}
