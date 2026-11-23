<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the run rate breaker and run admission know about a live trigger: whether it is paused, since when and why, and
 * how many of its runs were refused, with the last refusal. One row per trigger, made on its first pause or refusal.
 *
 * Kept apart from `flow_triggers`, which only a publish's sync writes (its definition) and the schedule's claim (its
 * slot): a publish never lifts a pause. The row goes with its trigger.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('flow_trigger_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('trigger_id')
                ->unique('flow_trigger_states_trigger_unique')
                ->constrained('flow_triggers')
                ->cascadeOnDelete();
            $table->timestamp('paused_at')->nullable();
            $table->string('paused_reason', 32)->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->unsignedBigInteger('refused_count')->default(0);
            $table->timestamp('last_refused_at')->nullable();
            $table->string('last_refusal_reason', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_trigger_states');
    }
};
