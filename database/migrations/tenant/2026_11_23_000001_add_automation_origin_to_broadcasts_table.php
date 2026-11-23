<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a flow's `send_message` node start a broadcast. The row says where it came from (`origin`), which flow, run and
 * node started it (no foreign keys: a flow or a run may be deleted, the broadcast stays), the once-only key of the node
 * execution that started it (`origin_key`), and the event chain depth its completion event carries.
 *
 * Two unique indexes hold the automation's guarantees in the database: one broadcast per node execution, and at most one
 * draft or running broadcast per flow node at a time. Both skip console broadcasts, whose columns are null.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table): void {
            $table->string('origin', 16)->default('console')->after('status');
            $table->uuid('flow_id')->nullable()->after('origin');
            $table->uuid('flow_session_id')->nullable()->after('flow_id');
            $table->string('flow_node_id', 191)->nullable()->after('flow_session_id');
            $table->string('origin_key', 64)->nullable()->unique()->after('flow_node_id');
            $table->unsignedSmallInteger('chain_depth')->default(0)->after('origin_key');
        });

        DB::statement(
            "CREATE UNIQUE INDEX broadcasts_live_flow_node_unique ON broadcasts (flow_id, flow_node_id) WHERE flow_node_id IS NOT NULL AND status IN ('draft', 'running')",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS broadcasts_live_flow_node_unique');

        Schema::table('broadcasts', function (Blueprint $table): void {
            $table->dropUnique(['origin_key']);
            $table->dropColumn(['origin', 'flow_id', 'flow_session_id', 'flow_node_id', 'origin_key', 'chain_depth']);
        });
    }
};
