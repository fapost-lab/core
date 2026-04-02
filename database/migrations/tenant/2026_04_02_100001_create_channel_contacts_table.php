<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('channel_contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignUuid('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->timestampTz('last_interaction_at')->nullable();
            $table->timestampsTz();

            $table->unique(['contact_id', 'channel_id'], 'channel_contacts_contact_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_contacts');
    }
};
