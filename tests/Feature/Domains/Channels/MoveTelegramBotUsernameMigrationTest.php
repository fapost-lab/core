<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Channels;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * Exercises database/migrations/tenant/2026_10_16_000002_move_telegram_bot_username_into_channel_config.php directly:
 * down() brings the old column back, rows are written the way the old code wrote them, and up() moves the names.
 */
final class MoveTelegramBotUsernameMigrationTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_up_moves_the_name_into_the_config_and_drops_the_column(): void
    {
        $migration = $this->migration();
        $migration->down();

        $named  = $this->row('telegram', 'acme_bot', '{"allowed_updates":["message"],"max_connections":12}');
        $empty  = $this->row('telegram', '', '{"max_connections":40}');
        $none   = $this->row('telegram', null, '[]');
        $list   = $this->row('telegram', 'list_bot', '[]');
        $nulled = $this->row('telegram', 'json_null_bot', 'null');
        $other  = $this->row('viber', 'stray', '{"region":"eu"}');

        $migration->up();

        $this->assertFalse(Schema::hasColumn('channels', 'telegram_bot_username'));
        // pgsql's jsonb keeps its own key order; the keys and values are what matter here.
        $this->assertEquals(['allowed_updates' => ['message'], 'max_connections' => 12, 'bot_username' => 'acme_bot'], $this->config($named));
        $this->assertSame(['max_connections' => 40], $this->config($empty));
        $this->assertSame([], $this->config($none));
        $this->assertSame(['bot_username' => 'list_bot'], $this->config($list));
        $this->assertSame(['bot_username' => 'json_null_bot'], $this->config($nulled));
        $this->assertSame(['region' => 'eu'], $this->config($other));
    }

    public function test_the_column_wins_over_a_name_already_in_the_config(): void
    {
        $migration = $this->migration();
        $migration->down();

        $id = $this->row('telegram', 'column_bot', '{"bot_username":"stale_bot"}');

        $migration->up();

        $this->assertSame(['bot_username' => 'column_bot'], $this->config($id));
    }

    public function test_down_restores_the_column_without_loss(): void
    {
        $channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'config' => ['max_connections' => 12, 'bot_username' => 'acme_bot'],
        ]));
        $viber = Channel::withoutEvents(fn (): Channel => Channel::factory()->create(['type' => 'viber', 'config' => ['bot_username' => 'kept']]));

        $migration = $this->migration();
        $migration->down();

        $row = DB::table('channels')->where('id', $channel->getKey())->first();

        $this->assertSame('acme_bot', $row->telegram_bot_username);
        $this->assertSame(['max_connections' => 12], json_decode((string) $row->config, true));
        $this->assertNull(DB::table('channels')->where('id', $viber->getKey())->value('telegram_bot_username'));
        $this->assertSame(['bot_username' => 'kept'], json_decode((string) DB::table('channels')->where('id', $viber->getKey())->value('config'), true));

        // The schema the other tests expect.
        $migration->up();
    }

    private function row(string $type, ?string $username, ?string $config): string
    {
        $id = mb_strtolower(Str::ulid()->toRfc4122());

        DB::table('channels')->insert([
            'id'                    => $id,
            'assistant_id'          => $this->assistant()->getKey(),
            'tenant_id'             => self::TENANT_ID,
            'type'                  => $type,
            'token'                 => 'token',
            'secret_token'          => 'secret',
            'webhook_public_hash'   => Str::random(48),
            'telegram_bot_username' => $username,
            'config'                => $config,
            'is_active'             => true,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        return $id;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function config(string $id): ?array
    {
        $raw = DB::table('channels')->where('id', $id)->value('config');

        return null === $raw ? null : json_decode((string) $raw, true);
    }

    private function assistant(): Assistant
    {
        return Assistant::query()->first() ?? Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/tenant/2026_10_16_000002_move_telegram_bot_username_into_channel_config.php');

        return $migration;
    }
}
