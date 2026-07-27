<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('assistant_id')->constrained('assistants')->cascadeOnDelete();
            $table->string('name');
            $table->text('message');
            $table->string('target_type', 16)->default('all');
            $table->jsonb('target_tags')->nullable();
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'assistant_id', 'status'], 'broadcasts_scope_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcasts');
    }
};
