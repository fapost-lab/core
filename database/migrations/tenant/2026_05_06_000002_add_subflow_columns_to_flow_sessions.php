<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds subflow lifecycle coordinates and explicit end_status to flow_sessions.
 *
 * - parent_session_id: when present, this session is a subflow child; resolves
 *   the parent that's currently in paused_subflow status awaiting our completion.
 * - parent_resume_node_id: subflow node ID in the parent flow that the child
 *   should return to. Combined with end_status -> selects the parent output handle.
 * - end_status: terminal status returned to parent on subflow end (success /
 *   cancelled / failed). NULL until the session reaches an end node.
 *
 * See ADR Subflow Composition for full lifecycle semantics.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->foreignUuid('parent_session_id')
                ->nullable()
                ->after('flow_definition_id')
                ->constrained('flow_sessions')
                ->nullOnDelete();

            $table->string('parent_resume_node_id')
                ->nullable()
                ->after('parent_session_id');

            $table->string('end_status')
                ->nullable()
                ->after('status');
        });

        if ('sqlite' !== Schema::getConnection()->getDriverName()) {
            Schema::getConnection()->statement(
                "ALTER TABLE flow_sessions ADD CONSTRAINT flow_sessions_end_status_check
                CHECK (end_status IS NULL OR end_status IN ('success', 'cancelled', 'failed'))"
            );

            Schema::getConnection()->statement(
                'CREATE INDEX flow_sessions_parent_session_id_idx ON flow_sessions (parent_session_id) WHERE parent_session_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        if ('sqlite' !== Schema::getConnection()->getDriverName()) {
            Schema::getConnection()->statement('DROP INDEX IF EXISTS flow_sessions_parent_session_id_idx');
            Schema::getConnection()->statement('ALTER TABLE flow_sessions DROP CONSTRAINT IF EXISTS flow_sessions_end_status_check');
        }

        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->dropForeign(['parent_session_id']);
            $table->dropColumn(['parent_session_id', 'parent_resume_node_id', 'end_status']);
        });
    }
};
