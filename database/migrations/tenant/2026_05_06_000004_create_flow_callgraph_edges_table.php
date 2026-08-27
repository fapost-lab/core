<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reverse-index of subflow caller→callee relations across published flow_definitions.
 *
 * Populated atomically inside PublishFlowDefinitionService when a definition with
 * subflow nodes is published: every subflow node's flow_id becomes a row here.
 * Used by CallGraphValidator to perform forward + reverse BFS on publish, detect
 * cycles, enforce depth ≤ 3, and refuse cross-assistant references.
 *
 * See ADR Subflow Composition.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('flow_callgraph_edges', function (Blueprint $table): void {
            $table->uuid('caller_flow_id');
            $table->uuid('callee_flow_id');
            $table->foreignUuid('caller_definition_id')
                ->constrained('flow_definitions')
                ->cascadeOnDelete();

            $table->primary(['caller_flow_id', 'callee_flow_id', 'caller_definition_id'], 'flow_callgraph_edges_pk');
            $table->index('callee_flow_id', 'flow_callgraph_edges_callee_idx');
            $table->index('caller_flow_id', 'flow_callgraph_edges_caller_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_callgraph_edges');
    }
};
