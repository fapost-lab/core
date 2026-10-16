<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::connection('landlord')->create('platform_settings', function (Blueprint $table): void {
            // A platform-wide setting someone stored. No row: the setting's default applies.
            $table->uuid('id')->primary();
            $table->string('key', 128)->unique();
            $table->jsonb('value');
            $table->timestampTz('updated_at');
            $table->string('updated_by');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('platform_settings');
    }
};
