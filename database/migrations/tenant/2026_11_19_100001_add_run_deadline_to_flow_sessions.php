<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The deadline of an automation run (adr-20261119-automation-run-deadline).
 *
 * - deadline_at is frozen when the run starts (its start plus the platform setting `flow.automation.max_run_hours`);
 *   subflow children copy their parent's. Conversations never get one. Rows written before this migration stay NULL
 *   and are measured from created_at instead, so no backfill is needed.
 * - failure_reason says why the run was failed from outside the engine (`deadline_exceeded`, `start_lost`).
 * - A partial index for the every-minute `flow:sweep-overdue-automation-runs`, over the live automation sessions only,
 *   so the query stays empty and cheap for most tenants. PostgreSQL only; the SQLite test database does without.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->timestamp('deadline_at')->nullable()->after('segment_id');
            $table->string('failure_reason', 64)->nullable()->after('deadline_at');
        });

        if ('pgsql' !== Schema::getConnection()->getDriverName()) {
            return;
        }

        Schema::getConnection()->statement(
            "CREATE INDEX IF NOT EXISTS flow_sessions_automation_deadline_idx ON flow_sessions (deadline_at, created_at) WHERE run_kind = 'automation' AND status IN ('pending', 'active', 'waiting_input', 'paused', 'paused_subflow')"
        );
    }

    public function down(): void
    {
        if ('pgsql' === Schema::getConnection()->getDriverName()) {
            Schema::getConnection()->statement('DROP INDEX IF EXISTS flow_sessions_automation_deadline_idx');
        }

        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->dropColumn(['deadline_at', 'failure_reason']);
        });
    }
};
