<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The grant an `oauth2_authorization_code` credential got by a person's consent: one row per credential, the tokens
 * encrypted with the application key, removed with the credential.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('credential_oauth_grants', function (Blueprint $table): void {
            $table->uuid('credential_id')->primary();
            $table->uuid('tenant_id');
            $table->string('status', 32);
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestampTz('access_expires_at')->nullable();
            $table->timestampTz('refresh_expires_at')->nullable();
            $table->string('granted_scope', 1024)->nullable();
            $table->uuid('connected_by')->nullable();
            $table->timestampTz('connected_at');
            $table->timestampTz('refreshed_at');
            $table->string('failure_code', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('credential_id', 'credential_oauth_grants_credential_fk')
                ->references('id')
                ->on('credentials')
                ->cascadeOnDelete();
            $table->index(['status', 'refreshed_at'], 'credential_oauth_grants_status_refreshed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_oauth_grants');
    }
};
