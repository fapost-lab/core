<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Exceptions\DraftVersionConflictException;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Services\SaveDraftService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SaveDraftServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('flow_drafts');
        Schema::create('flow_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('flow_id')->unique();
            $table->integer('draft_version')->default(1);
            $table->string('name');
            $table->json('nodes')->nullable();
            $table->timestamps();
        });
    }

    public function test_throws_draft_version_conflict_exception_on_stale_draft_version(): void
    {
        $draft   = FlowDraft::factory()->create(['draft_version' => 5]);
        $service = app(SaveDraftService::class);

        $this->expectException(DraftVersionConflictException::class);

        $service->execute($draft->flow_id, [], expectedDraftVersion: 3);
    }

    public function test_updates_nodes_and_returns_incremented_version(): void
    {
        $draft = FlowDraft::factory()->create([
            'draft_version' => 2,
            'nodes'         => [],
        ]);
        $service = app(SaveDraftService::class);

        $newVersion = $service->execute(
            $draft->flow_id,
            ['node_1' => ['id' => 'node_1', 'type' => 'input']],
            expectedDraftVersion: 2,
        );

        $this->assertSame(3, $newVersion);
        $draft->refresh();
        $this->assertSame(3, $draft->draft_version);
        $this->assertIsArray($draft->nodes);
        $this->assertSame('input', $draft->nodes['node_1']['type']);
    }
}
