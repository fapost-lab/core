<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Media;

use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFileReference;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use FAPost\Foundation\Media\Enums\MediaKind;
use Tests\Feature\FeatureTestCase;

final class MediaReferenceTrackerListenerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(TenantContextInterface::class)->set(
            new RuntimeTenant(
                id: '00000000-0000-0000-0000-000000000001',
                schemaName: 'main',
            )
        );
    }

    public function test_saving_flow_definition_with_media_creates_references(): void
    {
        $media = $this->makeMediaFile();

        $definition = FlowDefinition::query()->create([
            'tenant_id' => '00000000-0000-0000-0000-000000000001',
            'flow_id'   => 'flow-1',
            'version'   => 1,
            'name'      => 'Onboarding',
            'is_active' => false,
            'nodes'     => [
                [
                    'id'     => 'node-a',
                    'type'   => 'send_message',
                    'label'  => 'Welcome',
                    'config' => ['media_file_id' => $media->id],
                ],
                [
                    'id'     => 'node-b',
                    'type'   => 'send_message',
                    'config' => ['text' => 'no media here'],
                ],
            ],
            'edges'     => [],
        ]);

        $refs = MediaFileReference::query()
            ->where('reference_type', MediaFileReference::TYPE_FLOW_DEFINITION)
            ->where('reference_id', $definition->id)
            ->get();

        $this->assertCount(1, $refs);
        $this->assertSame($media->id, $refs->first()->media_file_id);
        $this->assertSame('Onboarding', $refs->first()->snapshot['flow_name'] ?? null);
        $this->assertSame('node-a', $refs->first()->snapshot['node_id'] ?? null);
    }

    public function test_resaving_flow_definition_replaces_previous_references(): void
    {
        $oldMedia = $this->makeMediaFile();
        $newMedia = $this->makeMediaFile(content: 'different');

        $definition = FlowDefinition::query()->create([
            'tenant_id' => '00000000-0000-0000-0000-000000000001',
            'flow_id'   => 'flow-2',
            'version'   => 1,
            'name'      => 'Onboarding v2',
            'is_active' => false,
            'nodes'     => [
                ['id' => 'n1', 'type' => 'send_message', 'config' => ['media_file_id' => $oldMedia->id]],
            ],
            'edges'     => [],
        ]);

        $definition->forceFill([
            'nodes' => [
                ['id' => 'n2', 'type' => 'send_message', 'config' => ['media_file_id' => $newMedia->id]],
            ],
        ])->save();

        $refs = MediaFileReference::query()
            ->where('reference_id', $definition->id)
            ->get();

        $this->assertCount(1, $refs);
        $this->assertSame($newMedia->id, $refs->first()->media_file_id);
    }

    private function makeMediaFile(string $content = 'demo'): MediaFile
    {
        $blob = MediaBlob::query()->create([
            'tenant_id'    => '00000000-0000-0000-0000-000000000001',
            'content_hash' => hash('sha256', $content),
            'storage_path' => 'tenants/test/media/' . hash('crc32', $content) . '.bin',
            'storage_disk' => 'local',
            'size'         => mb_strlen($content),
            'mime_type'    => 'application/octet-stream',
        ]);

        return MediaFile::query()->create([
            'tenant_id' => '00000000-0000-0000-0000-000000000001',
            'blob_id'   => $blob->id,
            'name'      => 'demo.bin',
            'kind'      => MediaKind::Document,
            'metadata'  => [],
            'source'    => MediaSource::Upload,
        ]);
    }
}
