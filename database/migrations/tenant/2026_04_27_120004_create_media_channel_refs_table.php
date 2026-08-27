<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('media_channel_refs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('blob_id')->constrained('media_blobs')->cascadeOnDelete();
            $table->foreignUuid('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('provider_file_id');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('uploaded_at');

            $table->unique(['blob_id', 'channel_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_channel_refs');
    }
};
