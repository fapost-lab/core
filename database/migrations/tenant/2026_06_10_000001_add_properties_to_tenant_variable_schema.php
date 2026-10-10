<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a `properties` JSONB column to tenant_variable_schema for storing
 * type-specific metadata (e.g. max_size and item_type for array variables).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_variable_schema', function (Blueprint $table): void {
            $table->jsonb('properties')->default('{}')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_variable_schema', function (Blueprint $table): void {
            $table->dropColumn('properties');
        });
    }
};
