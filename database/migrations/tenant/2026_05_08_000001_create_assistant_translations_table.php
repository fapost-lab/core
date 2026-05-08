<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('assistant_translations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('assistant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 255);
            $table->string('language', 10);
            $table->text('value');
            $table->timestamps();

            $table->unique(['assistant_id', 'key', 'language'], 'assistant_translations_assistant_key_language_unique');
            $table->index(['assistant_id', 'language'], 'assistant_translations_assistant_language_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_translations');
    }
};
