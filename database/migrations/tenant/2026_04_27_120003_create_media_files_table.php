<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('blob_id')->constrained('media_blobs')->restrictOnDelete();
            $table->foreignUuid('folder_id')->nullable()->constrained('media_folders')->nullOnDelete();
            $table->string('name');
            $table->string('kind', 16);
            $table->jsonb('metadata')->default('{}');
            $table->uuid('uploaded_by')->nullable();
            $table->string('source', 16);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['tenant_id', 'folder_id', 'deleted_at']);
            $table->index(['tenant_id', 'kind', 'deleted_at']);
            $table->index('blob_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
