<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert broadcasts.message from plain text to jsonb locale maps
 * `{ "<lang>": "..." }`. Existing non-empty values are preserved under the
 * "en" key — the catalog's last-resort fallback — which keeps already
 * created broadcasts sendable until the tenant edits them through the
 * multilingual input in BroadcastFormSchema.
 *
 * The column's original NOT NULL constraint is relaxed to nullable: "has
 * content" is now a per-locale, app-level rule (BroadcastFormSchema requires
 * the tenant's base language) rather than something a single scalar column
 * can express.
 */
return new class () extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ('pgsql' === $driver) {
            DB::statement('ALTER TABLE broadcasts ALTER COLUMN message DROP NOT NULL');

            // ALTER ... TYPE jsonb USING ... runs the conversion in place.
            // Empty strings collapse to NULL — readers expect either NULL or
            // a populated object, never an empty string masquerading as JSON.
            DB::statement(<<<'SQL'
                ALTER TABLE broadcasts
                    ALTER COLUMN message TYPE jsonb
                    USING (
                        CASE
                            WHEN message IS NULL OR message = '' THEN NULL
                            ELSE jsonb_build_object('en', message)
                        END
                    )
            SQL);

            return;
        }

        // SQLite test path: drop+recreate as JSON. Test datasets are seeded
        // per-test so dropping the existing values is harmless.
        Schema::table('broadcasts', function (Blueprint $table): void {
            $table->dropColumn(['message']);
        });

        Schema::table('broadcasts', function (Blueprint $table): void {
            $table->json('message')->nullable();
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
                ALTER TABLE broadcasts
                    ALTER COLUMN message TYPE text
                    USING (message ->> 'en')
            SQL);

            return;
        }

        Schema::table('broadcasts', function (Blueprint $table): void {
            $table->dropColumn(['message']);
        });

        Schema::table('broadcasts', function (Blueprint $table): void {
            $table->text('message')->nullable();
        });
    }
};
