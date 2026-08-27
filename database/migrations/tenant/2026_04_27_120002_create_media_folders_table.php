<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('parent_id')->nullable();
            $table->string('name');
            $table->string('path_cache', 1024);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'parent_id']);
            $table->index(['tenant_id', 'path_cache']);
        });

        // Self-referential FK must be added after table creation so PostgreSQL
        // can resolve the primary key constraint of the same table.
        Schema::table('media_folders', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('media_folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_folders');
    }
};
