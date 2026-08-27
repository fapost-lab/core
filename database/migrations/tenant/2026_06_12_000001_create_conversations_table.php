<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('assistant_id')->constrained('assistants')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignUuid('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('platform', 32);
            $table->string('status', 16)->default('open');
            $table->string('owner_type', 16)->nullable();
            $table->uuid('owner_staff_user_id')->nullable();
            $table->timestampTz('last_message_at')->nullable();
            $table->timestampTz('last_inbound_at')->nullable();
            $table->timestampTz('last_outbound_at')->nullable();
            $table->string('last_message_preview')->nullable();
            $table->integer('unread_count')->default(0);
            $table->bigInteger('message_count')->default(0);
            $table->jsonb('meta')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['tenant_id', 'assistant_id', 'contact_id', 'channel_id'],
                'conversations_thread_unique',
            );
            $table->index(['tenant_id', 'status', 'last_message_at'], 'conversations_inbox_idx');
            $table->index(['tenant_id', 'contact_id'], 'conversations_contact_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
