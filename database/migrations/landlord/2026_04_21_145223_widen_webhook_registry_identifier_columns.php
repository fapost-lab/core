<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::connection('landlord')->table('webhook_registry', function (Blueprint $table): void {
            $table->string('webhook_public_hash', 48)->change();
            $table->string('tenant_id', 36)->change();
            $table->string('assistant_id', 36)->change();
            $table->string('channel_id', 36)->change();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('webhook_registry', function (Blueprint $table): void {
            $table->string('webhook_public_hash', 48)->change();
            $table->string('tenant_id', 26)->change();
            $table->string('assistant_id', 26)->change();
            $table->string('channel_id', 36)->change();
        });
    }
};
