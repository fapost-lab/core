<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert assistant.fallback_message and assistant.busy_message from plain
 * text to jsonb locale maps `{ "<lang>": "..." }`. Existing non-empty values
 * are preserved under the "en" key — the catalog's last-resort fallback —
 * which keeps the bot working until the tenant edits each entry through the
 * multilingual input in AssistantSettings.
 *
 * `commands` JSONB column already holds free-form arrays so its inner
 * `text` / `response` strings will be reinterpreted as locale maps at
 * read-time without a schema change (see runtime resolvers).
 */
return new class () extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ('pgsql' === $driver) {
            // ALTER ... TYPE jsonb USING ... runs the conversion in place.
            // Empty strings collapse to NULL — readers expect either NULL or
            // a populated object, never an empty string masquerading as JSON.
            DB::statement(<<<'SQL'
                ALTER TABLE assistants
                    ALTER COLUMN fallback_message TYPE jsonb
                    USING (
                        CASE
                            WHEN fallback_message IS NULL OR fallback_message = '' THEN NULL
                            ELSE jsonb_build_object('en', fallback_message)
                        END
                    )
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE assistants
                    ALTER COLUMN busy_message TYPE jsonb
                    USING (
                        CASE
                            WHEN busy_message IS NULL OR busy_message = '' THEN NULL
                            ELSE jsonb_build_object('en', busy_message)
                        END
                    )
            SQL);

            return;
        }

        // SQLite test path: drop+recreate as JSON. Test datasets are seeded
        // per-test so dropping the existing values is harmless.
        Schema::table('assistants', function (Blueprint $table): void {
            $table->dropColumn(['fallback_message', 'busy_message']);
        });

        Schema::table('assistants', function (Blueprint $table): void {
            $table->json('fallback_message')->nullable();
            $table->json('busy_message')->nullable();
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ('pgsql' === $driver) {
            // Rollback flattens the locale map — losing every locale beyond
            // 'en'. Acceptable because the migration is intentionally a one-way
            // shape change; running the suite forward again restores schema.
            DB::statement(<<<'SQL'
                ALTER TABLE assistants
                    ALTER COLUMN fallback_message TYPE text
                    USING (fallback_message ->> 'en')
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE assistants
                    ALTER COLUMN busy_message TYPE text
                    USING (busy_message ->> 'en')
            SQL);

            return;
        }

        Schema::table('assistants', function (Blueprint $table): void {
            $table->dropColumn(['fallback_message', 'busy_message']);
        });

        Schema::table('assistants', function (Blueprint $table): void {
            $table->text('fallback_message')->nullable();
            $table->text('busy_message')->nullable();
        });
    }
};
