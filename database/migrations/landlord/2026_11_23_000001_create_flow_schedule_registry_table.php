<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The landlord index of schedule triggers: which tenant has a trigger due when. Derived data — the truth is each
 * tenant's `flow_triggers.next_run_at` — so it has no foreign key and can be rebuilt from the tenants at any time
 * (adr-20261123-flow-schedule-trigger). Times are UTC.
 */
return new class () extends Migration {
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::connection('landlord')->create('flow_schedule_registry', function (Blueprint $table): void {
            $table->uuid('trigger_id')->primary();
            $table->uuid('tenant_id')->index();
            $table->timestamp('next_run_at')->index();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('flow_schedule_registry');
    }
};
