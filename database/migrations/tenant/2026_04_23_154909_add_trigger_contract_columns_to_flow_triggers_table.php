<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('flow_triggers', function (Blueprint $table): void {
            $table->unique('flow_id', 'flow_triggers_flow_id_unique');
        });

        // Driver check is intentionally inline here because this migration must keep
        // DDL compatible across test SQLite and production PostgreSQL connections.
        if ('pgsql' === DB::connection()->getDriverName()) {
            DB::statement('ALTER TABLE flow_triggers DROP CONSTRAINT IF EXISTS flow_triggers_type_check');
            DB::statement(
                "ALTER TABLE flow_triggers ADD CONSTRAINT flow_triggers_type_check CHECK (type IN ('message', 'schedule', 'webhook', 'api', 'event'))"
            );
        }
    }

    public function down(): void
    {
        if ('pgsql' === DB::connection()->getDriverName()) {
            DB::statement('ALTER TABLE flow_triggers DROP CONSTRAINT IF EXISTS flow_triggers_type_check');
            DB::statement(
                "ALTER TABLE flow_triggers ADD CONSTRAINT flow_triggers_type_check CHECK (type IN ('message', 'schedule', 'webhook', 'api'))"
            );
        }

        Schema::table('flow_triggers', function (Blueprint $table): void {
            $table->dropUnique('flow_triggers_flow_id_unique');
        });
    }
};
