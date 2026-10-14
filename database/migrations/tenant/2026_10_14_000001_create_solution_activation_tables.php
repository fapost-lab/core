<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('solution_activations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('solution_id', 32)->unique();
            $table->string('status', 16);
            $table->timestamp('activated_at');
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('solution_activation_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('solution_id', 32);
            $table->string('action', 16);
            $table->string('actor_kind', 16);
            $table->uuid('actor_id')->nullable();
            $table->string('actor_label')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['solution_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solution_activation_events');
        Schema::dropIfExists('solution_activations');
    }
};
