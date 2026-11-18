<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a flow session run without a contact or an assistant: the base of contactless runs started by schedule,
 * event, webhook and API (spec flow-automations, "Run model").
 *
 * - contact_id becomes nullable; its foreign key keeps cascadeOnDelete (a contact's runs go with the contact).
 * - assistant_id becomes nullable and its foreign key is re-created with nullOnDelete, so the history of a run
 *   outlives the assistant it belonged to.
 * - run_kind says what started the session: `conversation` (every existing row, through the default) or
 *   `automation`. A session without a contact is always an automation (pgsql CHECK).
 * - trigger_id is the trigger that fired the run. It has no foreign key: trigger ids become stable ids in the
 *   flow definition, and a run's history must not disappear with its trigger.
 *
 * The indexes on contact_id and assistant_id are not column attributes and survive the change.
 *
 * down() restores NOT NULL and cascadeOnDelete; it fails while a session without a contact or an assistant
 * exists, rather than deleting those rows.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->dropForeign(['assistant_id']);
        });

        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->uuid('assistant_id')->nullable()->change();
            $table->uuid('contact_id')->nullable()->change();
        });

        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->foreign('assistant_id')->references('id')->on('assistants')->nullOnDelete();

            $table->string('run_kind', 16)->default('conversation')->after('status');
            $table->uuid('trigger_id')->nullable()->after('run_kind');
        });

        if ('pgsql' === Schema::getConnection()->getDriverName()) {
            Schema::getConnection()->statement(
                "ALTER TABLE flow_sessions ADD CONSTRAINT flow_sessions_run_kind_check
                CHECK (run_kind IN ('conversation', 'automation'))"
            );

            Schema::getConnection()->statement(
                "ALTER TABLE flow_sessions ADD CONSTRAINT flow_sessions_contactless_automation_check
                CHECK (contact_id IS NOT NULL OR run_kind = 'automation')"
            );
        }
    }

    public function down(): void
    {
        if ('pgsql' === Schema::getConnection()->getDriverName()) {
            Schema::getConnection()->statement('ALTER TABLE flow_sessions DROP CONSTRAINT IF EXISTS flow_sessions_contactless_automation_check');
            Schema::getConnection()->statement('ALTER TABLE flow_sessions DROP CONSTRAINT IF EXISTS flow_sessions_run_kind_check');
        }

        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->dropColumn(['run_kind', 'trigger_id']);
            $table->dropForeign(['assistant_id']);
        });

        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->uuid('assistant_id')->nullable(false)->change();
            $table->uuid('contact_id')->nullable(false)->change();
        });

        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->foreign('assistant_id')->references('id')->on('assistants')->cascadeOnDelete();
        });
    }
};
