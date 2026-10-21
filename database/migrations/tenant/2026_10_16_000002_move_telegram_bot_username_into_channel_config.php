<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The bot name Telegram reports lived in its own column, `channels.telegram_bot_username`, which only one channel type
 * could use. It becomes the managed `bot_username` key of `channels.config`, where any integration keeps what the
 * provider tells it, and the column is dropped.
 *
 * The column wins over a `bot_username` already in `config` (Telegram's form never stored that key). A channel whose
 * name is empty is left alone, and `down()` restores the column from the key without loss.
 */
return new class () extends Migration {
    public function up(): void
    {
        if ('pgsql' === Schema::getConnection()->getDriverName()) {
            DB::statement(<<<'SQL'
                UPDATE channels
                   SET config = ((CASE WHEN jsonb_typeof(config::jsonb) = 'object' THEN config::jsonb ELSE '{}'::jsonb END) || jsonb_build_object('bot_username', telegram_bot_username))::json
                 WHERE type = 'telegram' AND telegram_bot_username IS NOT NULL AND telegram_bot_username <> ''
            SQL);
        } else {
            DB::statement(<<<'SQL'
                UPDATE channels
                   SET config = json_set(CASE WHEN json_type(COALESCE(config, '{}')) = 'object' THEN COALESCE(config, '{}') ELSE '{}' END, '$.bot_username', telegram_bot_username)
                 WHERE type = 'telegram' AND telegram_bot_username IS NOT NULL AND telegram_bot_username <> ''
            SQL);
        }

        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn('telegram_bot_username');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->string('telegram_bot_username')->nullable()->after('secret_token');
        });

        if ('pgsql' === Schema::getConnection()->getDriverName()) {
            DB::statement(<<<'SQL'
                UPDATE channels
                   SET telegram_bot_username = config::jsonb ->> 'bot_username',
                       config = (config::jsonb - 'bot_username')::json
                 WHERE type = 'telegram' AND jsonb_typeof(config::jsonb) = 'object' AND jsonb_exists(config::jsonb, 'bot_username')
            SQL);
        } else {
            DB::statement(<<<'SQL'
                UPDATE channels
                   SET telegram_bot_username = json_extract(config, '$.bot_username'),
                       config = json_remove(config, '$.bot_username')
                 WHERE type = 'telegram' AND json_type(config) = 'object' AND json_type(config, '$.bot_username') IS NOT NULL
            SQL);
        }
    }
};
