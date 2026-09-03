<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Broadcasting;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Models\Broadcast;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * Exercises database/migrations/tenant/2026_08_07_000001_localize_broadcast_message.php
 * directly. The migration already ran once as part of the test harness's tenant
 * migration setup — down()/up() here roll the *pgsql* branch back and forward
 * around a manually seeded legacy row to prove the text-to-locale-map
 * conversion is real, not just structural.
 */
final class LocalizeBroadcastMessageMigrationTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_up_migrates_existing_text_into_the_base_language_and_down_reverts(): void
    {
        if ('pgsql' !== DB::getDriverName()) {
            $this->markTestSkipped('The text<->jsonb data conversion only runs on the pgsql branch; the sqlite branch intentionally drops data (see migration docblock).');
        }

        $migration = $this->migration();
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);

        $migration->down();

        DB::table('broadcasts')->insert([
            'id'           => mb_strtolower(Str::ulid()->toRfc4122()),
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $assistant->getKey(),
            'name'         => 'Legacy promo',
            'message'      => 'Hello there!',
            'target_type'  => 'all',
            'status'       => BroadcastStatus::Draft->value,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $migration->up();

        /** @var Broadcast $broadcast */
        $broadcast = Broadcast::query()->where('name', 'Legacy promo')->firstOrFail();
        $this->assertSame(['en' => 'Hello there!'], $broadcast->message);

        $migration->down();

        $row = DB::table('broadcasts')->where('name', 'Legacy promo')->first();
        $this->assertSame('Hello there!', $row->message);

        // Restore the jsonb shape so later tests in the same process (and the
        // FeatureTestCase transaction rollback) see the schema they expect.
        $migration->up();
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/tenant/2026_08_07_000001_localize_broadcast_message.php');

        return $migration;
    }
}
