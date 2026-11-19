<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 120);
            $table->string('type', 64);
            $table->jsonb('settings');
            $table->jsonb('allowed_hosts');
            $table->text('secret');
            $table->timestampTz('secret_changed_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'name'], 'credentials_tenant_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credentials');
    }
};
