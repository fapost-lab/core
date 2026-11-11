<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant's audit log: what its staff (and platform support, inside the tenant) did in the console and the builder.
 *
 * The table spatie/laravel-activitylog expects, with ULID keys in `uuid` columns (ADR-03) instead of the package's
 * bigint stub, and the actor and subject snapshots the screen filters and shows. Entries are never updated, so there
 * is no `updated_at`; they are deleted only by `audit:prune`.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('log_name', 32)->nullable();
            $table->string('description', 64);
            $table->string('event', 32)->nullable();
            $table->nullableUuidMorphs('subject');
            $table->nullableUuidMorphs('causer');
            $table->string('actor_kind', 16);
            $table->string('actor_label')->nullable();
            $table->string('subject_label')->nullable();
            $table->uuid('assistant_id')->nullable();
            $table->jsonb('attribute_changes')->nullable();
            $table->jsonb('properties')->nullable();
            $table->timestamp('created_at');

            $table->index('created_at');
            $table->index(['log_name', 'created_at']);
            $table->index(['assistant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
