<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('status', 32)->default('active')->after('email');
            $table->string('phone')->nullable()->after('name');
        });

        if ('pgsql' === Schema::getConnection()->getDriverName()) {
            DB::statement('ALTER TABLE users ALTER COLUMN password DROP NOT NULL');
        } else {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('password')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['status', 'phone']);
        });

        if ('pgsql' === Schema::getConnection()->getDriverName()) {
            DB::statement('ALTER TABLE users ALTER COLUMN password SET NOT NULL');
        } else {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('password')->nullable(false)->change();
            });
        }
    }
};
