<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('media_blobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->char('content_hash', 64);
            $table->string('storage_path');
            $table->string('storage_disk', 32);
            $table->unsignedBigInteger('size');
            $table->string('mime_type', 128);
            $table->timestamps();

            $table->unique(['tenant_id', 'content_hash']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_blobs');
    }
};
