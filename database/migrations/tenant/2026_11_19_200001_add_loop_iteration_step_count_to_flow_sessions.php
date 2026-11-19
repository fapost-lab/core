<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The steps an automation run has taken in its current loop iteration — since the last loop_end, or since it entered
 * the loop body — over all its segments, so "one iteration takes at most flow.execution.max_iterations steps" holds
 * for the whole run instead of restarting in every segment. Zero outside a loop body; existing rows start at zero.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->unsignedInteger('loop_iteration_step_count')->default(0)->after('loop_step_count');
        });
    }

    public function down(): void
    {
        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->dropColumn('loop_iteration_step_count');
        });
    }
};
