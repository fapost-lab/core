<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('platform', 20);
            $table->string('external_id', 255);
            $table->jsonb('meta')->default('{}');
            $table->jsonb('attributes')->default('{}');
            $table->timestamps();

            $table->unique(['tenant_id', 'platform', 'external_id'], 'contacts_tenant_platform_external_unique');
            $table->index('tenant_id', 'contacts_tenant_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
