<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if ('sqlite' === Schema::getConnection()->getDriverName()) {
            return;
        }

        Schema::getConnection()->statement(
            'ALTER TABLE flow_sessions DROP CONSTRAINT IF EXISTS flow_sessions_status_check'
        );
        Schema::getConnection()->statement(
            "ALTER TABLE flow_sessions ADD CONSTRAINT flow_sessions_status_check
            CHECK (status IN ('pending', 'active', 'waiting_input', 'paused', 'completed', 'failed', 'cancelled'))"
        );
    }

    public function down(): void
    {
        if ('sqlite' === Schema::getConnection()->getDriverName()) {
            return;
        }

        Schema::getConnection()->statement(
            'ALTER TABLE flow_sessions DROP CONSTRAINT IF EXISTS flow_sessions_status_check'
        );
        Schema::getConnection()->statement(
            "ALTER TABLE flow_sessions ADD CONSTRAINT flow_sessions_status_check
            CHECK (status IN ('pending', 'active', 'waiting_input', 'paused', 'completed', 'failed'))"
        );
    }
};
