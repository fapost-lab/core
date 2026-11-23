<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant's API tokens (`adr-20261119-tenant-api-tokens` §2): only the SHA-256 of the secret is stored. No
 * `tenant_id`: the table lives in the tenant's schema, which is the boundary, as for `users`.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->char('token_hash', 64)->unique('api_tokens_token_hash_unique');
            $table->char('last_four', 4);
            $table->jsonb('abilities');
            $table->uuid('created_by')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expiry_notified_at')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index('expires_at', 'api_tokens_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
