<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table): void {
            $table->string('stop_reason', 64)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table): void {
            $table->dropColumn('stop_reason');
        });
    }
};
