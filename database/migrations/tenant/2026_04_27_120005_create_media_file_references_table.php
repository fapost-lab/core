<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('media_file_references', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('media_file_id')->constrained('media_files')->cascadeOnDelete();
            $table->string('reference_type', 32);
            $table->uuid('reference_id');
            $table->jsonb('snapshot');
            $table->timestamp('created_at');

            $table->index('media_file_id');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_file_references');
    }
};
