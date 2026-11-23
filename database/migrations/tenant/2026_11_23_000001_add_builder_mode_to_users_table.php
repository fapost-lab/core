<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The flow builder mode a staff user works in: `tree` or `graph`. `null` means the user never chose, which reads as
 * the tree, the builder's original layout.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('builder_mode', 16)->nullable()->after('onboarding_checklist_hidden');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('builder_mode');
        });
    }
};
