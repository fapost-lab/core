<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The setup checklist a staff user hid, as the keys of the steps that were registered at that moment. `null` means the
 * user never hid it. The checklist comes back by itself when a step appears that is not in the list.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('onboarding_checklist_hidden')->nullable()->after('pinned_applications');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('onboarding_checklist_hidden');
        });
    }
};
