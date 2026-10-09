<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('limit_refusals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('limit_key');
            // Hash of the refused identity, never the identity itself.
            $table->string('subject_hash', 64);
            $table->uuid('channel_id');
            $table->date('refused_on');
            $table->unsignedInteger('attempts')->default(1);
            $table->timestampTz('last_refused_at');
            $table->timestamps();

            $table->unique(['limit_key', 'subject_hash', 'channel_id', 'refused_on']);
            $table->index('refused_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limit_refusals');
    }
};
