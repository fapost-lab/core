<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('assistant_id')->constrained('assistants')->restrictOnDelete();
            $table->uuid('tenant_id');
            $table->string('type', 20);
            $table->text('token');
            $table->text('secret_token');
            $table->string('webhook_public_hash', 64)->unique();
            $table->json('config')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

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
