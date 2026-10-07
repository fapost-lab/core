<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::connection('landlord')->create('support_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // No foreign key: the row is short lived and the tenant may be gone before it is pruned.
            $table->uuid('tenant_id');
            $table->string('token_hash', 64)->unique();
            $table->string('operator_ref');
            $table->string('operator_name');
            $table->string('operator_email');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at');

            $table->index('tenant_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('support_access_tokens');
    }
};
