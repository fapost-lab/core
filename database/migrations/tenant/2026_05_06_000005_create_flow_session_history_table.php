<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in audit trail of state changes and lifecycle events per session.
 *
 * Engine and ContactWriter auto-instrument writes here when the running
 * flow_definition has logging_enabled = true. Otherwise, no rows are written
 * (NoOpHistoryLogger). Distinct from flow_logs (raw operational log, 30-day
 * retention, partitioned) — this table is business audit, retained per
 * tenant policy (V1: forever; V1.x: configurable retention).
 *
 * Event types:
 *  - state_change       — single path mutation (path, old_value, new_value)
 *  - subflow_started    — parent recorded child session creation
 *  - subflow_returned   — parent recorded child session end with outcome
 *  - node_entered       — engine entered a node
 *  - node_failed        — handler raised or session was failed at this node
 *
 * See ADR State Writer Semantics.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('flow_session_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('session_id')->constrained('flow_sessions')->cascadeOnDelete();
            $table->string('node_id', 128);
            $table->string('event_type', 64);
            $table->string('path')->nullable();
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at');

            $table->index(['session_id', 'created_at'], 'flow_session_history_session_idx');
            $table->index(['tenant_id', 'event_type', 'created_at'], 'flow_session_history_tenant_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_session_history');
    }
};
