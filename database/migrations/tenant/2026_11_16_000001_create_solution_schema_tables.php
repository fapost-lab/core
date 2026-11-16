<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a Solution's own tenant migrations are recorded in the tenant's schema: one journal entry per migration
 * applied (kept apart from Core's `migrations` table, where two Solutions could ship a file of the same name),
 * and one row per Solution — the Solution's footprint in the schema — with the newest migration applied here and the digest
 * of the package's migration names the schema was last brought up to (equal to the installed package's: the schema is current).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('solution_migrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('solution_id', 32);
            $table->string('migration');
            $table->timestamp('ran_at');

            $table->unique(['solution_id', 'migration']);
        });

        Schema::create('solution_schemas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('solution_id', 32)->unique();
            $table->string('latest_migration');
            $table->string('migrations_digest', 64);
            $table->timestamp('migrated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solution_schemas');
        Schema::dropIfExists('solution_migrations');
    }
};
