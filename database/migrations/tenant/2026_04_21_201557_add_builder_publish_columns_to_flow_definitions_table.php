<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS flow_definitions_flow_id_version_unique ON flow_definitions (flow_id, version)'
        );

        Schema::table('flow_definitions', function (Blueprint $table): void {
            $table->timestamp('published_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS flow_definitions_flow_id_version_unique');

        Schema::table('flow_definitions', function (Blueprint $table): void {
            $table->dropColumn('published_at');
        });
    }
};
