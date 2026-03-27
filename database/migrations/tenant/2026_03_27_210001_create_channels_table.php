<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table): void {
            // Explicit index on assistant_id for Channel::where('assistant_id', ...) / FK lookups; PostgreSQL does not auto-index the referencing column.
            $table->uuid('id')->primary();
            $table->uuid('assistant_id');
            $table->uuid('tenant_id');
            $table->string('type', 20);
            $table->text('token');
            $table->text('secret_token');
            $table->string('webhook_public_hash', 64)->unique();
            $table->json('config')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('assistant_id')->references('id')->on('assistants')->restrictOnDelete();
            $table->index('tenant_id');
            $table->index('assistant_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
