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
            // Deterministic partition for the migration month (matches 2026_04_08_* filename), not runtime "now()".
            $anchor     = Carbon::create(2026, 4, 1, 0, 0, 0, 'UTC');
            $monthStart = $anchor->copy()->startOfMonth()->format('Y-m-d H:i:sP');
            $monthEnd   = $anchor->copy()->addMonth()->startOfMonth()->format('Y-m-d H:i:sP');
            $suffix     = $anchor->format('Ym');

            DB::statement(<<<'SQL'
CREATE TABLE flow_logs (
    id uuid NOT NULL,
    session_id uuid NOT NULL,
    node_id varchar(64) NULL,
    node_type varchar(64) NULL,
    node_version smallint NOT NULL DEFAULT 1,
    status varchar(32) NOT NULL,
    source_handle varchar(64) NULL,
    next_node_id varchar(64) NULL,
    resolved jsonb NOT NULL DEFAULT '{}'::jsonb,
    error text NULL,
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamp with time zone NOT NULL,
    PRIMARY KEY (id, created_at),
    CONSTRAINT flow_logs_session_id_foreign FOREIGN KEY (session_id) REFERENCES flow_sessions (id) ON DELETE CASCADE
) PARTITION BY RANGE (created_at)
SQL);

            DB::statement("CREATE TABLE flow_logs_{$suffix} PARTITION OF flow_logs FOR VALUES FROM ('{$monthStart}') TO ('{$monthEnd}')");
            DB::statement('CREATE TABLE flow_logs_default PARTITION OF flow_logs DEFAULT');

            DB::statement('CREATE INDEX flow_logs_session_id_index ON flow_logs (session_id)');

            return;
        }

        Schema::create('flow_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('flow_sessions')->cascadeOnDelete();
            $table->string('node_id', 64)->nullable();
            $table->string('node_type', 64)->nullable();
            $table->smallInteger('node_version')->default(1);
            $table->string('status', 32);
            $table->string('source_handle', 64)->nullable();
            $table->string('next_node_id', 64)->nullable();
            $table->json('resolved')->default('{}');
            $table->text('error')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ('pgsql' === $driver) {
            DB::statement('DROP TABLE IF EXISTS flow_logs CASCADE');

            return;
        }

        Schema::dropIfExists('flow_logs');
    }
};
