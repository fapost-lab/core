<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds expression engine snapshot field and opt-in history logging flag to flow_definitions.
 *
 * - expression_engine: stable identifier of the engine that was active for the tenant when
 *   this definition was published. Snapshot is immutable per definition row — running
 *   sessions evaluate against the engine ID stored here, not the current tenant config.
 *   See ADR Expression Language.
 * - logging_enabled: per-flow opt-in flag for structured state-change history into
 *   flow_session_history. Off by default. See ADR State Writer Semantics.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('flow_definitions', function (Blueprint $table): void {
            $table->string('expression_engine')->default('template')->after('edges');
            $table->boolean('logging_enabled')->default(false)->after('expression_engine');
        });
    }

    public function down(): void
    {
        Schema::table('flow_definitions', function (Blueprint $table): void {
            $table->dropColumn(['expression_engine', 'logging_enabled']);
        });
    }
};
