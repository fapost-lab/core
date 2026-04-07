<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('flow_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('assistant_id')->constrained('assistants')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignUuid('flow_definition_id')->constrained('flow_definitions')->restrictOnDelete();
            $table->unsignedInteger('flow_version');
            $table->string('current_node_id')->nullable();
            $table->jsonb('state')->default('{}');
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'assistant_id', 'contact_id']);
            $table->index(['contact_id', 'status']);
            $table->index(['flow_definition_id', 'status']);
            $table->index('flow_definition_id');
        });

        if ('sqlite' !== DB::getDriverName()) {
            DB::statement(
                "ALTER TABLE flow_sessions ADD CONSTRAINT flow_sessions_status_check
                CHECK (status IN ('pending', 'active', 'waiting_input', 'paused', 'completed', 'failed'))"
            );
        }
    }

    public function down(): void
    {
        if ('sqlite' !== DB::getDriverName()) {
            DB::statement('ALTER TABLE flow_sessions DROP CONSTRAINT IF EXISTS flow_sessions_status_check');
        }

        Schema::dropIfExists('flow_sessions');
    }
};
