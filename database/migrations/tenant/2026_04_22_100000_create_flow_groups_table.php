<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('flow_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('assistant_id');
            $table->string('name');
            $table->timestamps();

            $table->index(['tenant_id', 'assistant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_groups');
    }
};
