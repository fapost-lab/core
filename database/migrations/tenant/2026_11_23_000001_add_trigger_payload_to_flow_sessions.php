<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The trigger payload a root automation run started with: the exact initial state its trigger handed over (a webhook's
 * request, an event's payload), kept apart from `state`, which the flow may overwrite as it runs. A re-run from the
 * start hands it to the new run unchanged. NULL for conversations, subflow children and runs started before the column
 * existed — none of them can be re-run. Not encrypted: the same data is already in `state`; it goes with the row when
 * run retention deletes the run.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->jsonb('trigger_payload')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->dropColumn('trigger_payload');
        });
    }
};
