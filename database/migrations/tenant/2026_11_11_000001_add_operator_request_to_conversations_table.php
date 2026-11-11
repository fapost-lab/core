<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A flow's `request_operator` node records its request on the thread, not only in the flow session: the session may
 * end while an operator handles the conversation. `operator_request_id` is what conditional updates match on;
 * `operator_request` holds the rest of the request (state, source session and node, texts) and is rewritten whole.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->uuid('operator_request_id')->nullable()->after('queued_at');
            $table->jsonb('operator_request')->nullable()->after('operator_request_id');
            $table->index(['operator_request_id'], 'conversations_operator_request_idx');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_operator_request_idx');
            $table->dropColumn(['operator_request_id', 'operator_request']);
        });
    }
};
