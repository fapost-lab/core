<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\PersistentInlineButton;
use App\Domains\Flow\Retention\FlowVersionPruner;
use App\Domains\Flow\Templates\TemplateUsageRepository;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFileReference;
use Fapost\Foundation\Media\Enums\MediaKind;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * Flow version retention keeps the last N versions of every flow and deletes an older one only when nothing needs
 * it: never the active or the highest version, one a session or a persistent button points at, one a subflow node
 * pins, or another tenant's.
 */
final class FlowVersionPrunerTest extends FeatureTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
    }

    public function test_only_versions_outside_the_last_n_of_each_flow_are_deleted(): void
    {
        $flowId = $this->publish(60);
        $other  = $this->publish(3);

        $this->assertSame(10, $this->pruner()->prune($this->tenantId, 50));

        $this->assertSame(range(11, 60), $this->versions($flowId));
        $this->assertSame([1, 2, 3], $this->versions($other));
    }

    public function test_a_dry_run_counts_and_deletes_nothing(): void
    {
        $flowId = $this->publish(5);

        $this->assertSame(3, $this->pruner()->prune($this->tenantId, 2, dryRun: true));

        $this->assertSame([1, 2, 3, 4, 5], $this->versions($flowId));
    }

    public function test_zero_keeps_every_version(): void
    {
        $flowId = $this->publish(5);

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, 0));

        $this->assertSame([1, 2, 3, 4, 5], $this->versions($flowId));
    }

    public function test_the_active_and_the_highest_version_are_kept_whatever_n_is(): void
    {
        $flowId = $this->publish(5, activeVersion: 2);

        $this->assertSame(3, $this->pruner()->prune($this->tenantId, 1));

        $this->assertSame([2, 5], $this->versions($flowId));
    }

    public function test_a_version_any_session_runs_is_kept(): void
    {
        $flowId = $this->publish(6);

        $this->flowRun($this->definition($flowId, 1), FlowRunKind::Conversation, FlowSessionStatus::Completed);
        $this->flowRun($this->definition($flowId, 2), FlowRunKind::Automation, FlowSessionStatus::WaitingInput);
        $this->flowRun($this->definition($flowId, 3), FlowRunKind::Automation, FlowSessionStatus::Failed);

        $this->assertSame(1, $this->pruner()->prune($this->tenantId, 2));

        $this->assertSame([1, 2, 3, 5, 6], $this->versions($flowId));
    }

    public function test_a_version_a_persistent_button_points_at_is_kept(): void
    {
        $flowId = $this->publish(4);

        PersistentInlineButton::query()->create([
            'tenant_id'           => $this->tenantId,
            'contact_id'          => Contact::factory()->forTenant($this->tenantId)->create()->getKey(),
            'external_message_id' => '42',
            'original_session_id' => (string) Str::uuid(),
            'flow_definition_id'  => $this->definition($flowId, 1)->getKey(),
            'node_id'             => 'send',
            'button_id'           => (string) Str::uuid(),
        ]);

        $this->assertSame(1, $this->pruner()->prune($this->tenantId, 2));

        $this->assertSame([1, 3, 4], $this->versions($flowId));
    }

    public function test_a_version_a_subflow_node_pins_in_a_draft_or_any_version_is_kept(): void
    {
        $child  = $this->publish(6);
        $parent = $this->publish(1, nodes: [$this->subflow($child, 1)]);

        FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => Assistant::factory()->create()->getKey(),
            'flow_id'      => $parent,
            'nodes'        => [$this->subflow($child, '2'), $this->subflow($child, null)],
        ]);

        $old = $this->publish(3, nodes: [$this->subflow($child, 3.0)]);

        $this->assertSame(4, $this->pruner()->prune($this->tenantId, 1));

        $this->assertSame([1, 2, 3, 6], $this->versions($child));
        $this->assertSame([3], $this->versions($old));
    }

    public function test_a_pin_written_after_the_first_scan_is_seen_under_the_batch_lock(): void
    {
        $child     = $this->publish(4);
        $assistant = Assistant::factory()->create();
        $written   = false;

        // A parent is saved pinning the child's v2 right after the run's first scan of the drafts.
        DB::listen(function (QueryExecuted $query) use (&$written, $child, $assistant): void {
            if ($written || ! str_contains($query->sql, 'from "flow_drafts"')) {
                return;
            }

            $written = true;

            FlowDraft::factory()->create([
                'tenant_id'    => $this->tenantId,
                'assistant_id' => $assistant->getKey(),
                'nodes'        => [$this->subflow($child, 2)],
            ]);
        });

        $this->assertSame(2, $this->pruner()->prune($this->tenantId, 1));

        $this->assertTrue($written);
        $this->assertSame([2, 4], $this->versions($child));
    }

    public function test_another_tenants_versions_are_never_deleted(): void
    {
        $foreign = $this->publish(5, tenantId: (string) Str::uuid());

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, 1));

        $this->assertSame([1, 2, 3, 4, 5], $this->versions($foreign));
    }

    public function test_a_deleted_version_takes_its_media_references_and_template_usages_but_not_the_file(): void
    {
        $flowId   = $this->publish(3);
        $old      = $this->definition($flowId, 1);
        $kept     = $this->definition($flowId, 3);
        $template = (string) Str::uuid();
        $file     = $this->mediaFile();

        foreach ([$old, $kept] as $definition) {
            MediaFileReference::query()->create([
                'media_file_id'  => $file->getKey(),
                'reference_type' => MediaFileReference::TYPE_FLOW_DEFINITION,
                'reference_id'   => $definition->getKey(),
                'snapshot'       => ['flow_name' => 'Versions'],
            ]);
        }

        DB::connection()->table('flow_template_usages')->insert([
            ['host_flow_id' => $flowId, 'host_definition_id' => $old->getKey(), 'template_flow_id' => $template],
        ]);

        $this->assertSame(2, $this->pruner()->prune($this->tenantId, 1));

        $this->assertSame([(string) $kept->getKey()], MediaFileReference::query()->pluck('reference_id')->map(static fn ($id): string => (string) $id)->all());
        $this->assertTrue(MediaFile::query()->whereKey($file->getKey())->exists());
        $this->assertSame([], $this->app->make(TemplateUsageRepository::class)->hostsOf($template));
    }

    private function pruner(): FlowVersionPruner
    {
        return new FlowVersionPruner(batchSize: 2);
    }

    /**
     * Publishes versions 1…$count of a new flow, the highest (or `$activeVersion`) active.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    private function publish(int $count, ?int $activeVersion = null, array $nodes = [], ?string $tenantId = null): string
    {
        $flowId = (string) Str::uuid();

        for ($version = 1; $version <= $count; ++$version) {
            FlowDefinition::query()->create([
                'tenant_id' => $tenantId ?? $this->tenantId,
                'flow_id'   => $flowId,
                'version'   => $version,
                'name'      => 'Versions',
                'nodes'     => [...$nodes, ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
                'edges'     => [],
                'is_active' => ($activeVersion ?? $count) === $version,
            ]);
        }

        return $flowId;
    }

    /**
     * @return array<string, mixed>
     */
    private function subflow(string $childFlowId, mixed $pin): array
    {
        return ['id' => 'sub', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $childFlowId, 'flow_version' => $pin]];
    }

    /**
     * @return list<int>
     */
    private function versions(string $flowId): array
    {
        return FlowDefinition::query()->where('flow_id', $flowId)->orderBy('version')->pluck('version')->map(static fn ($version): int => (int) $version)->all();
    }

    private function definition(string $flowId, int $version): FlowDefinition
    {
        return FlowDefinition::query()->where('flow_id', $flowId)->where('version', $version)->firstOrFail();
    }

    private function flowRun(FlowDefinition $definition, FlowRunKind $kind, FlowSessionStatus $status): void
    {
        FlowSession::query()->create([
            'tenant_id'          => $this->tenantId,
            'contact_id'         => FlowRunKind::Conversation === $kind ? Contact::factory()->forTenant($this->tenantId)->create()->getKey() : null,
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => $definition->version,
            'status'             => $status,
            'run_kind'           => $kind,
            'state'              => [],
        ]);
    }

    private function mediaFile(): MediaFile
    {
        $blob = MediaBlob::query()->create([
            'tenant_id'    => $this->tenantId,
            'content_hash' => hash('sha256', 'versions'),
            'storage_path' => 'tenants/test/media/versions.bin',
            'storage_disk' => 'local',
            'size'         => 100,
            'mime_type'    => 'application/octet-stream',
        ]);

        return MediaFile::query()->create([
            'tenant_id' => $this->tenantId,
            'blob_id'   => $blob->getKey(),
            'name'      => 'used.bin',
            'kind'      => MediaKind::Document,
            'metadata'  => [],
            'source'    => 'upload',
        ]);
    }
}
