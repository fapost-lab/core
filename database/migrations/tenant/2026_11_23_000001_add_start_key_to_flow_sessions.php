<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The key of a start a `start_flow` node queued for a contact: the run's execution key and the node id. Unique, so
 * a repeated step, a retried job or two jobs racing start the contact's conversation once. NULLs stay distinct, so
 * every session started any other way never collides.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->string('start_key', 191)->nullable();

            $table->unique('start_key', 'flow_sessions_start_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('flow_sessions', function (Blueprint $table): void {
            $table->dropUnique('flow_sessions_start_key_unique');
            $table->dropColumn('start_key');
        });
    }
};
