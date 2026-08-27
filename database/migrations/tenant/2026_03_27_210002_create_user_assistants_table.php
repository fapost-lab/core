<?php

declare(strict_types=1);

/**
 * Task 06b (Staff / access-control domain): {@code user_assistants} pivot — schema ownership lives here.
 * Task 06.2 / Filament: consumes this pivot only (e.g. {@see App\Domains\Staff\Models\User::assistants}); do not reintroduce a second migration for the same table.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('user_assistants', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('assistant_id');
            $table->primary(['user_id', 'assistant_id']);
            $table->foreign('assistant_id')->references('id')->on('assistants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_assistants');
    }
};
