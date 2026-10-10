<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->string('webhook_status', 16)->nullable()->after('webhook_public_hash');
            $table->timestamp('webhook_status_at')->nullable()->after('webhook_status');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn(['webhook_status', 'webhook_status_at']);
        });
    }
};
