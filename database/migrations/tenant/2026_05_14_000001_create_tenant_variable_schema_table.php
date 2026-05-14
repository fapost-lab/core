<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant registry of declared variable types.
 *
 * One row per unique (storage, group, name) triple. Upserted atomically by
 * PublishFlowService after each successful publish so consumers can coerce
 * values to their declared type at runtime without scanning node JSON.
 *
 * Rows are soft-linked to the declaring flow_definition via
 * declared_in_flow_id (nullable FK with SET NULL on delete) so that dropping
 * a flow_definition only orphans the type declaration — the variable still
 * exists in contact attributes and may be re-declared by a future publish.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('tenant_variable_schema', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('storage', 16);
            $table->string('group', 64)->nullable();
            $table->string('name', 64);
            $table->string('type', 16);
            $table->uuid('declared_in_flow_id')->nullable();
            $table->string('declared_by_node_id', 128)->nullable();
            $table->timestampTz('updated_at');

            $table->unique(['storage', 'group', 'name'], 'tenant_variable_schema_unique');
            $table->index('tenant_id', 'tenant_variable_schema_tenant_idx');
            $table->index('declared_in_flow_id', 'tenant_variable_schema_flow_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_variable_schema');
    }
};
