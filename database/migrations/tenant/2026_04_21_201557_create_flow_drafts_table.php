<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('flow_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('flow_id');
            $table->integer('draft_version')->default(1);
            $table->string('name');
            $table->jsonb('nodes')->default('{}');
            $table->timestamps();

            $table->unique(['flow_id']);
            $table->index(['tenant_id', 'flow_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_drafts');
    }
};
