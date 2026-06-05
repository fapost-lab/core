<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flow visibility flag on published definitions.
 *
 * Mirrors `flow_drafts.is_public`. A non-public (private) flow may only be
 * started by a contact whose `is_authenticated` flag is set — enforced at flow
 * start by the FlowAccessPolicy. Defaults to true so existing flows stay open.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('flow_definitions', function (Blueprint $table): void {
            $table->boolean('is_public')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('flow_definitions', function (Blueprint $table): void {
            $table->dropColumn('is_public');
        });
    }
};
