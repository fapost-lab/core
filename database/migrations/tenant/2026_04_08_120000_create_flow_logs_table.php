<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ('pgsql' === $driver) {
            DB::statement(<<<'SQL'
CREATE TABLE flow_logs (
    id uuid NOT NULL,
    session_id uuid NOT NULL,
    node_id varchar(128) NOT NULL,
    node_type varchar(128) NOT NULL,
    node_version integer NOT NULL,
    status varchar(32) NOT NULL CHECK (status IN ('executed', 'failed', 'conflict', 'terminal')),
    source_handle varchar(64) NULL,
    state_changes jsonb NULL,
    resolved jsonb NULL,
    error jsonb NULL,
    created_at timestamp with time zone NOT NULL,
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at)
SQL);

            DB::statement('CREATE INDEX flow_logs_session_id_index ON flow_logs (session_id)');
            DB::statement('CREATE INDEX flow_logs_created_at_index ON flow_logs (created_at)');
            DB::statement('ALTER TABLE flow_logs ADD CONSTRAINT flow_logs_session_id_foreign FOREIGN KEY (session_id) REFERENCES flow_sessions (id) ON DELETE CASCADE');

            $start = Carbon::now('UTC')->startOfMonth();
            for ($i = 0; $i < 3; $i++) {
                $month      = $start->copy()->addMonths($i);
                $monthStart = $month->copy()->startOfMonth()->format('Y-m-d H:i:sP');
                $monthEnd   = $month->copy()->addMonth()->startOfMonth()->format('Y-m-d H:i:sP');
                $suffix     = $month->format('Y_m');
                DB::statement("CREATE TABLE IF NOT EXISTS flow_logs_{$suffix} PARTITION OF flow_logs FOR VALUES FROM ('{$monthStart}') TO ('{$monthEnd}')");
            }

            Schema::create('analytics_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('event_type');
                $table->jsonb('payload');
                $table->timestampTz('occurred_at');
                $table->index(['tenant_id', 'event_type', 'occurred_at'], 'analytics_events_tenant_type_occurred_idx');
            });

            return;
        }

        Schema::create('flow_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('flow_sessions')->cascadeOnDelete();
            $table->string('node_id', 128);
            $table->string('node_type', 128);
            $table->integer('node_version');
            $table->string('status', 32);
            $table->string('source_handle', 64)->nullable();
            $table->jsonb('state_changes')->nullable();
            $table->jsonb('resolved')->nullable();
            $table->jsonb('error')->nullable();
            $table->timestampTz('created_at')->index();
        });

        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('event_type');
            $table->jsonb('payload');
            $table->timestampTz('occurred_at');
            $table->index(['tenant_id', 'event_type', 'occurred_at'], 'analytics_events_tenant_type_occurred_idx');
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ('pgsql' === $driver) {
            Schema::dropIfExists('analytics_events');
            DB::statement('DROP TABLE IF EXISTS flow_logs CASCADE');

            return;
        }

        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('flow_logs');
    }
};
