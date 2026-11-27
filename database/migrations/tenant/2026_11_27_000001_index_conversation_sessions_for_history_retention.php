<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * A partial index for the daily session history retention (`flow:prune-session-history`), which walks finished
 * conversation sessions by `(updated_at, id)`: without it every run scans all of `flow_sessions`. Partial on
 * `run_kind = 'conversation'`, the counterpart of `flow_sessions_automation_retention_idx`. PostgreSQL only; the SQLite
 * test database does without.
 */
return new class () extends Migration {
    public function up(): void
    {
        if ('pgsql' !== Schema::getConnection()->getDriverName()) {
            return;
        }

        Schema::getConnection()->statement(
            "CREATE INDEX IF NOT EXISTS flow_sessions_conversation_retention_idx ON flow_sessions (updated_at, id) WHERE run_kind = 'conversation'"
        );
    }

    public function down(): void
    {
        if ('pgsql' !== Schema::getConnection()->getDriverName()) {
            return;
        }

        Schema::getConnection()->statement('DROP INDEX IF EXISTS flow_sessions_conversation_retention_idx');
    }
};
