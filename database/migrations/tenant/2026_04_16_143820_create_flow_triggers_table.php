<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('flow_triggers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('assistant_id')->nullable();
            $table->uuid('flow_id');
            $table->string('type', 32);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->jsonb('config');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'is_active']);
            $table->index(['assistant_id', 'type', 'is_active']);
            $table->index(['type', 'is_active']);
            $table->foreign('assistant_id')->references('id')->on('assistants')->nullOnDelete();
        });

        // FK policy:
        // - assistant_id has FK and nullOnDelete to preserve global triggers on assistant removal.
        // - flow_id intentionally has no FK because it references logical flow identity (flow_definitions.flow_id),
        //   which is versioned and not a single referential row in storage.

        if ('pgsql' === DB::connection()->getDriverName()) {
            DB::statement(
                "ALTER TABLE flow_triggers ADD CONSTRAINT flow_triggers_type_check CHECK (type IN ('message', 'schedule', 'webhook', 'api'))"
            );
            DB::statement(
                "CREATE INDEX flow_triggers_schedule_next_run_at_index ON flow_triggers (next_run_at) WHERE TYPE = 'schedule' AND is_active = TRUE AND next_run_at IS NOT NULL"
            );
        } else {
            $this->createScheduleFallbackIndex();
        }
    }

    public function down(): void
    {
        Schema::table('flow_triggers', function (Blueprint $table): void {
            $table->dropForeign(['assistant_id']);
        });

        if ('pgsql' === DB::connection()->getDriverName()) {
            DB::statement('DROP INDEX IF EXISTS flow_triggers_schedule_next_run_at_index');
            DB::statement('ALTER TABLE flow_triggers DROP CONSTRAINT IF EXISTS flow_triggers_type_check');
        } else {
            DB::statement('DROP INDEX IF EXISTS flow_triggers_schedule_next_run_at_index');
        }

        Schema::dropIfExists('flow_triggers');
    }

    private function createScheduleFallbackIndex(): void
    {
        Schema::table('flow_triggers', function (Blueprint $table): void {
            $table->index('next_run_at', 'flow_triggers_schedule_next_run_at_index');
        });
    }
};
