<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('contact_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'name'], 'contact_groups_tenant_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_groups');
    }
};
