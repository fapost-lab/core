<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical `is_authenticated` flag on contacts.
 *
 * Raised by the `auth_request` node once a contact passes a challenge (the
 * basic challenge compares a variable against an expected value). Kept as a
 * canonical column — not in `attributes` — because it participates in
 * access-control hot paths, mirroring the precedent set by `language`.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->boolean('is_authenticated')->default(false)->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn('is_authenticated');
        });
    }
};
