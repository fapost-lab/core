<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('media_channel_refs', function (Blueprint $table): void {
            $table->string('provider_kind', 32)->nullable()->after('provider_file_id');
        });
    }

    public function down(): void
    {
        Schema::table('media_channel_refs', function (Blueprint $table): void {
            $table->dropColumn('provider_kind');
        });
    }
};
