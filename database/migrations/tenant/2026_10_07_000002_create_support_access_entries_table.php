<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('support_access_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('operator_ref');
            $table->string('operator_name');
            $table->string('operator_email');
            $table->string('ip', 45)->nullable();
            $table->timestamp('entered_at');
            $table->timestamp('left_at')->nullable();

            $table->index('entered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_access_entries');
    }
};
