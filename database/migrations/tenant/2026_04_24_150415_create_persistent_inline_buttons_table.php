<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores registrations for inline keyboard buttons that persist across flow sessions.
 *
 * Each row maps a (contact, original_session_id, button_id) triple to the flow definition
 * snapshot and node that originally sent the button. Enables re-entry into a flow branch
 * when the user presses an old inline button after their active session has ended.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('persistent_inline_buttons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->uuid('contact_id');
            $table->string('external_message_id');
            // The session_id that was encoded in callback_data when this button was sent.
            $table->string('original_session_id');
            // Snapshot definition — no FK; old definitions may be replaced but rows kept for re-entry.
            $table->uuid('flow_definition_id');
            // The send_message node inside that definition.
            $table->string('node_id');
            // One row per button in the keyboard.
            $table->uuid('button_id');
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['tenant_id', 'contact_id', 'original_session_id', 'button_id'],
                'pib_lookup_idx',
            );

            $table->foreign('contact_id')
                ->references('id')
                ->on('contacts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persistent_inline_buttons');
    }
};
