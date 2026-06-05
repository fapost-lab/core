<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('assistants', function (Blueprint $table): void {
            $table->jsonb('available_countries')->default('[]')->after('default_language');
        });
    }

    public function down(): void
    {
        Schema::table('assistants', function (Blueprint $table): void {
            $table->dropColumn('available_countries');
        });
    }
};
