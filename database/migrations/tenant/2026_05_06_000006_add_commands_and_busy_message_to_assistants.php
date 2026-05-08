<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds tenant-configurable global commands and busy_message to assistants.
 *
 * - commands JSONB: list of per-assistant slash commands. Each entry has
 *   shape {command, type, response?|text?|flow_id?, label?}. Three V1 action
 *   types: terminate_session, start_flow, send_message. Validated on save by
 *   AssistantCommandsRule. Built-in /reset and /cancel are inherited from
 *   BuiltinCommandsRegistry — tenants may override response text only.
 * - busy_message: optional text used by DropPolicy when lock acquisition fails
 *   after backoff retries. Falls back to a platform default when null.
 *
 * See ADR Message Routing & Concurrency Control.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('assistants', function (Blueprint $table): void {
            $table->jsonb('commands')->default('[]')->after('settings');
            $table->text('busy_message')->nullable()->after('commands');
        });
    }

    public function down(): void
    {
        Schema::table('assistants', function (Blueprint $table): void {
            $table->dropColumn(['commands', 'busy_message']);
        });
    }
};
