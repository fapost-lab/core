<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A connector's channel type may be up to 32 characters, and a contact's platform is the type of the channel it wrote
 * to. Both columns were `string(20)`; both stay required. The unique index on contacts (tenant, platform, external id)
 * is kept by the database across the change.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->string('type', 32)->change();
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('platform', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->string('type', 20)->change();
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('platform', 20)->change();
        });
    }
};
