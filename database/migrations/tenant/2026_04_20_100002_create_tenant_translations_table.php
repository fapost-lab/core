<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('tenant_translations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('key', 255);
            $table->string('language', 10);
            $table->text('value');
            $table->timestamps();

            $table->unique(['tenant_id', 'key', 'language'], 'tenant_translations_tenant_key_language_unique');
            $table->index(['tenant_id', 'language'], 'tenant_translations_tenant_language_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_translations');
    }
};
