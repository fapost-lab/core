<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces ON DELETE CASCADE with RESTRICT so assistant/channel rows are only removed
 * through Eloquent (observers clean Redis). DB-level CASCADE would skip observers.
 *
 * The standalone {@code assistant_id} index created alongside the table is not dropped by {@code dropForeign};
 * it remains for scoped channel queries.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropForeign(['assistant_id']);
        });

        Schema::table('channels', function (Blueprint $table): void {
            $table->foreign('assistant_id')->references('id')->on('assistants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropForeign(['assistant_id']);
        });

        Schema::table('channels', function (Blueprint $table): void {
            $table->foreign('assistant_id')->references('id')->on('assistants')->cascadeOnDelete();
        });
    }
};
