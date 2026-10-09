<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            // Idempotency key of a reservation; NULL for tenants created without one, which a unique index ignores.
            $table->string('reservation_key', 64)->nullable()->unique();
            $table->timestampTz('provisioning_lease_until')->nullable();
            $table->timestampTz('schema_claimed_at')->nullable();
            $table->timestampTz('provisioning_failed_at')->nullable();
            $table->string('provisioning_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->dropUnique(['reservation_key']);
            $table->dropColumn([
                'reservation_key',
                'provisioning_lease_until',
                'schema_claimed_at',
                'provisioning_failed_at',
                'provisioning_error',
            ]);
        });
    }
};
