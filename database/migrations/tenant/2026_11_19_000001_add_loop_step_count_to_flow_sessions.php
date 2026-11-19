<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The loop-aware step budget of automation runs: step_count keeps the steps outside loop bodies over all segments
 * (capped by flow.execution.max_iterations), loop_step_count the steps inside loop bodies; their sum is capped by the
 * engine's hard ceiling, so a runaway inside a loop body cannot keep dispatching itself either.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->unsignedInteger('loop_step_count')->default(0)->after('step_count');
        });
    }

    public function down(): void
    {
        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->dropColumn('loop_step_count');
        });
    }
};
