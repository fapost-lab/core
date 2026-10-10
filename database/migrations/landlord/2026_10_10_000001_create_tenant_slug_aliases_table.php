<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::connection('landlord')->create('tenant_slug_aliases', function (Blueprint $table): void {
            // A slug a tenant gave up. The row stays for good (the slug remains the tenant's);
            // `redirect_until` only says how long the old host still redirects.
            $table->string('slug')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->timestampTz('redirect_until')->nullable();
            $table->timestampTz('created_at');

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('tenant_slug_aliases');
    }
};
