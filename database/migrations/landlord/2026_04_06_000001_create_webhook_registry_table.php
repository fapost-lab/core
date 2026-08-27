<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::connection('landlord')->create('webhook_registry', function (Blueprint $table): void {
            $table->string('webhook_public_hash', 48)->primary();
            $table->string('tenant_id', 26)->index();
            $table->string('assistant_id', 26);
            $table->string('channel_id', 36);
            $table->string('schema');
            $table->string('platform', 50);
            $table->text('secret_token');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('webhook_registry');
    }
};
