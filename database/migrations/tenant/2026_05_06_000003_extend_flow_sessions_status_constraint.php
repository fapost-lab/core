<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Extends flow_sessions.status check constraint to support subflow lifecycle
 * and global-command termination per ADRs.
 *
 * Added values:
 *  - paused_subflow      — parent is suspended while a child subflow is running
 *  - terminated_by_user  — global command (/reset, /cancel) forced session end
 *  - expired             — child timeout job force-failed the session
 *  - ended               — synonym for completed in subflow contexts (kept for
 *                          forward-compat; "completed" remains valid)
 */
return new class () extends Migration {
    public function up(): void
    {
        if ('sqlite' === Schema::getConnection()->getDriverName()) {
            return;
        }

        Schema::getConnection()->statement('ALTER TABLE flow_sessions DROP CONSTRAINT IF EXISTS flow_sessions_status_check');
        Schema::getConnection()->statement(
            "ALTER TABLE flow_sessions ADD CONSTRAINT flow_sessions_status_check
            CHECK (status IN (
                'pending', 'active', 'waiting_input', 'paused', 'paused_subflow',
                'completed', 'ended', 'cancelled', 'failed', 'expired', 'terminated_by_user'
            ))"
        );
    }

    public function down(): void
    {
        if ('sqlite' === Schema::getConnection()->getDriverName()) {
            return;
        }

        Schema::getConnection()->statement('ALTER TABLE flow_sessions DROP CONSTRAINT IF EXISTS flow_sessions_status_check');
        Schema::getConnection()->statement(
            "ALTER TABLE flow_sessions ADD CONSTRAINT flow_sessions_status_check
            CHECK (status IN ('pending', 'active', 'waiting_input', 'paused', 'completed', 'failed', 'cancelled'))"
        );
    }
};
