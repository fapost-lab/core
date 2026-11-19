<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How deep in a chain of events a session was started: 0 for a run no event started (a message, a schedule), n for
 * a run an event of depth n started. An event raised inside a session of depth d has depth d + 1; one deeper than the
 * limit starts nothing (`flow.event.chain_depth_exceeded`). Kept on the row so the depth survives segments,
 * delay resumes and every other job boundary; subflow children copy their parent's.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->smallInteger('event_chain_depth')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->dropColumn('event_chain_depth');
        });
    }
};
