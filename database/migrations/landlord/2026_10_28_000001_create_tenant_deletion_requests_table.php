<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::connection('landlord')->create('tenant_deletion_requests', function (Blueprint $table): void {
            // A workspace's owner asking to delete it: the removal starts at `starts_at` unless an admin cancels first.
            // Requester and canceller are snapshots (a mail names them; the user may be gone by then).
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('requested_by_user_id');
            $table->string('requested_by_name');
            $table->string('requested_by_email');
            $table->timestampTz('requested_at');
            $table->timestampTz('starts_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by_user_id')->nullable();
            $table->string('cancelled_by_name')->nullable();
            $table->string('cancelled_by_email')->nullable();
            $table->timestampTz('started_at')->nullable();

            $table->index('tenant_id');
            $table->index('starts_at');
        });

        // At most one active request per tenant: the second of two racing requests fails on this index.
        DB::connection('landlord')->statement(
            'CREATE UNIQUE INDEX tenant_deletion_requests_one_active ON tenant_deletion_requests (tenant_id) WHERE cancelled_at IS NULL AND started_at IS NULL',
        );
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('tenant_deletion_requests');
    }
};
