<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent, segmented execution of automation runs (spec flow-automations, "Execution and duplicates").
 *
 * - fire_key is what fired the trigger: a schedule slot, an event id, a webhook delivery id. The unique index on
 *   (trigger_id, fire_key) is what makes a start happen once: a retried job finds the run it already created. NULLs
 *   stay distinct, so subflow children (they copy trigger_id, never fire_key) and runs without a fire key never
 *   collide. The index also serves lookups by trigger_id.
 * - step_count is the steps an automation session ran over all its segments; the engine's step limit applies to it,
 *   so a loop with no exit cannot keep dispatching itself.
 * - segment_id names the one continuation allowed to run the session next; any other continuation is stale.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->string('fire_key', 191)->nullable()->after('trigger_id');
            $table->unsignedInteger('step_count')->default(0)->after('fire_key');
            $table->uuid('segment_id')->nullable()->after('step_count');

            $table->unique(['trigger_id', 'fire_key'], 'flow_sessions_trigger_fire_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->dropUnique('flow_sessions_trigger_fire_key_unique');
            $table->dropColumn(['fire_key', 'step_count', 'segment_id']);
        });
    }
};
