<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::connection('landlord')->create('platform_setting_events', function (Blueprint $table): void {
            // One change of a platform setting; a null value is "not stored" (the default applied).
            $table->uuid('id')->primary();
            $table->string('key', 128);
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value')->nullable();
            $table->string('changed_by');
            $table->timestampTz('changed_at');

            $table->index(['key', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('platform_setting_events');
    }
};
