<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A question an `ask_person` node put to a contact, kept outside the run's session so it outlives the run: a press
 * that arrives after the run died, was reset or moved on is still recognised and answered "already decided".
 *
 * `token` is 128 random bits (hex), carried with the button's index in the payload of every button; `execution_key`
 * is the engine's key of the step that asked, so a retried step finds the same question. A question goes with its
 * session (run retention deletes the run) and with its addressee (erasing a contact).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('flow_questions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->char('token', 32)->unique('flow_questions_token_unique');
            $table->uuid('session_id');
            $table->string('node_id');
            $table->string('execution_key');
            $table->uuid('contact_id');
            $table->uuid('assistant_id')->nullable();
            $table->jsonb('buttons');
            $table->string('status', 16)->default('pending');
            $table->string('answer_button_id')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('expires_at');
            $table->string('external_message_id')->nullable();
            $table->text('late_answer_text')->nullable();
            $table->timestamps();

            $table->foreign('session_id', 'flow_questions_session_fk')
                ->references('id')->on('flow_sessions')->cascadeOnDelete();
            $table->foreign('contact_id', 'flow_questions_contact_fk')
                ->references('id')->on('contacts')->cascadeOnDelete();
            $table->unique(['session_id', 'node_id', 'execution_key'], 'flow_questions_execution_unique');
            $table->index(['status', 'expires_at'], 'flow_questions_status_expires_idx');
            $table->index('contact_id', 'flow_questions_contact_idx');
        });

        if ('sqlite' !== Schema::getConnection()->getDriverName()) {
            Schema::getConnection()->statement(
                "ALTER TABLE flow_questions ADD CONSTRAINT flow_questions_status_check
                CHECK (status IN ('pending', 'answered', 'expired', 'closed'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_questions');
    }
};
