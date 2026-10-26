<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The inbox is organised by who handles a thread, not by a lifecycle status: a thread can wait for an operator
 * (`owner_type = queued`), and `queued_at` says since when. The index serves the handling filter of one
 * assistant's list, newest activity first.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->timestampTz('queued_at')->nullable()->after('owner_staff_user_id');
            $table->index(['tenant_id', 'assistant_id', 'owner_type', 'last_message_at'], 'conversations_handling_idx');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_handling_idx');
            $table->dropColumn('queued_at');
        });
    }
};
