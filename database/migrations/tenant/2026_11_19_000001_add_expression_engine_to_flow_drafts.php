<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The expression engine the author chose for the flow (or template), on the editable draft surface.
 *
 * PublishFlowService snapshots it onto flow_definitions.expression_engine at publish, like logging_enabled, so a
 * later change of the draft never changes a published version. Every existing draft gets 'template', the only
 * engine there has ever been.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('flow_drafts', function (Blueprint $table): void {
            $table->string('expression_engine', 32)->default('template')->after('logging_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('flow_drafts', function (Blueprint $table): void {
            $table->dropColumn('expression_engine');
        });
    }
};
