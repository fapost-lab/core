<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reverse index of where a flow template is placed: one row per (host definition, template) pair, written when
 * the host is published. Only the host's active definition has rows — a publish replaces every row of its host
 * flow — so the index answers "which flows use this template now" for propagation and for refusing to delete a
 * template that is still in use.
 *
 * Kept apart from `flow_callgraph_edges`: subflow edges drive cycle and depth checks, template usages drive
 * republishing, and the two must not be confused.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('flow_template_usages', function (Blueprint $table): void {
            $table->uuid('host_flow_id');
            $table->foreignUuid('host_definition_id')
                ->constrained('flow_definitions')
                ->cascadeOnDelete();
            $table->uuid('template_flow_id');

            $table->primary(['host_definition_id', 'template_flow_id'], 'flow_template_usages_pk');
            $table->index('template_flow_id', 'flow_template_usages_template_idx');
            $table->index('host_flow_id', 'flow_template_usages_host_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_template_usages');
    }
};
