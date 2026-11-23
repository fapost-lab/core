<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Opens `flow_triggers.type` to the trigger types extensions register (`adr-20261123-solution-trigger-types`): on
 * PostgreSQL the CHECK that allowed only the built-in types is dropped, and the column is widened from 32 to 64
 * characters, since a Solution's type is "<solution id>.<name>" and the id alone may take 32. The column stays
 * required with no default; its indexes are kept by the database across the change.
 *
 * `down()` fails, changing nothing, once a row holds a type longer than 32 characters or one that is not built in.
 */
return new class () extends Migration {
    public function up(): void
    {
        // The CHECK exists only on PostgreSQL (2026_04_23_154909); the driver is a property of the schema migrated.
        if ('pgsql' === DB::connection()->getDriverName()) {
            DB::statement('ALTER TABLE flow_triggers DROP CONSTRAINT IF EXISTS flow_triggers_type_check');
        }

        Schema::table('flow_triggers', static function (Blueprint $table): void {
            $table->string('type', 64)->change();
        });
    }

    public function down(): void
    {
        Schema::table('flow_triggers', static function (Blueprint $table): void {
            $table->string('type', 32)->change();
        });

        if ('pgsql' === DB::connection()->getDriverName()) {
            DB::statement(
                "ALTER TABLE flow_triggers ADD CONSTRAINT flow_triggers_type_check CHECK (type IN ('message', 'schedule', 'webhook', 'api', 'event'))"
            );
        }
    }
};
