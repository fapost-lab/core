<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('status');
        });

        Schema::table('roles', function (Blueprint $table): void {
            $table->smallInteger('priority')->default(0)->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn('priority');
        });
    }
};
