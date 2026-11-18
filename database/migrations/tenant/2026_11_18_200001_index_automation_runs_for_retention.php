<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * A partial index for the daily automation run retention (`flow:prune-automation-runs`), which walks finished
 * automation sessions by `(updated_at, id)`: without it every run scans all of `flow_sessions`, conversation sessions
 * included. Partial on `run_kind = 'automation'`, so conversation sessions do not grow it. PostgreSQL only; the SQLite
 * test database does without.
 */
return new class () extends Migration {
    public function up(): void
    {
        if ('pgsql' !== Schema::getConnection()->getDriverName()) {
            return;
        }

        Schema::getConnection()->statement(
            "CREATE INDEX IF NOT EXISTS flow_sessions_automation_retention_idx ON flow_sessions (updated_at, id) WHERE run_kind = 'automation'"
        );
    }

    public function down(): void
    {
        if ('pgsql' !== Schema::getConnection()->getDriverName()) {
            return;
        }

        Schema::getConnection()->statement('DROP INDEX IF EXISTS flow_sessions_automation_retention_idx');
    }
};
