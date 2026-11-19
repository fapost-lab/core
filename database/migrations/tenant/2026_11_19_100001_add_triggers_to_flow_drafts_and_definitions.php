<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A flow carries several triggers, each with a stable id, in its draft and in every published definition; publishing
 * syncs `flow_triggers` by that id, which is the row's primary key.
 *
 * `null` in `triggers` means "not carried yet": the flow's live `flow_triggers` rows are its triggers until the draft
 * is saved or the flow published, so no data is backfilled here. `flow_triggers` may now hold several rows per flow.
 *
 * `down()` restores the one-row-per-flow unique index; it fails, deleting nothing, when a flow already has several.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('flow_drafts', function (Blueprint $table): void {
            $table->jsonb('triggers')->nullable();
        });

        Schema::table('flow_definitions', function (Blueprint $table): void {
            $table->jsonb('triggers')->nullable();
        });

        Schema::table('flow_triggers', function (Blueprint $table): void {
            $table->dropUnique('flow_triggers_flow_id_unique');
            $table->unique(['flow_id', 'id'], 'flow_triggers_flow_id_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('flow_triggers', function (Blueprint $table): void {
            $table->dropUnique('flow_triggers_flow_id_id_unique');
            $table->unique('flow_id', 'flow_triggers_flow_id_unique');
        });

        Schema::table('flow_definitions', function (Blueprint $table): void {
            $table->dropColumn('triggers');
        });

        Schema::table('flow_drafts', function (Blueprint $table): void {
            $table->dropColumn('triggers');
        });
    }
};
