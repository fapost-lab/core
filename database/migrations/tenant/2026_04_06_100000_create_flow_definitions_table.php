<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('flow_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('flow_id');
            $table->unsignedInteger('version');
            $table->string('name');
            $table->jsonb('nodes');
            $table->jsonb('edges');
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['flow_id', 'version']);
            $table->index(['tenant_id', 'flow_id']);
        });

        DB::statement('CREATE UNIQUE INDEX flow_definitions_active_unique ON flow_definitions (tenant_id, flow_id) WHERE is_active = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS flow_definitions_active_unique');
        Schema::dropIfExists('flow_definitions');
    }
};
