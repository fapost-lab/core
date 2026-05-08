<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors flow_definitions.logging_enabled on the editable draft surface.
 *
 * Authors set the flag in Filament against the draft; PublishFlowService copies
 * it onto the new flow_definitions row at publish time. Off by default — the
 * runtime audit trail in flow_session_history is opt-in per flow.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('flow_drafts', function (Blueprint $table): void {
            $table->boolean('logging_enabled')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('flow_drafts', function (Blueprint $table): void {
            $table->dropColumn('logging_enabled');
        });
    }
};
