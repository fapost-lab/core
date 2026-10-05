<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Conversation;

use App\Domains\Conversation\Store\Postgres\ConversationPartitionManager;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Listing and dropping partitions against a real PostgreSQL: the default test
 * run uses sqlite, so this skips there. Run with a pgsql DB_CONNECTION.
 * Creates two throwaway schemas holding identically named partitions, the way
 * every tenant schema does, and proves the manager only sees the current one.
 */
#[Group('pgsql')]
final class ConversationPartitionManagerPgsqlTest extends TestCase
{
    private const array SCHEMAS = ['cr_test_a', 'cr_test_b'];

    private string $originalSearchPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        if ('pgsql' !== DB::connection()->getDriverName()) {
            $this->markTestSkipped('Requires a PostgreSQL connection.');
        }

        $this->originalSearchPath = (string)DB::selectOne('SHOW search_path')->search_path;

        foreach (self::SCHEMAS as $schema) {
            DB::statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
            DB::statement("CREATE SCHEMA {$schema}");
            DB::statement("SET search_path TO {$schema}");
            DB::statement('CREATE TABLE conversation_messages (id uuid NOT NULL, created_at timestamptz NOT NULL) PARTITION BY RANGE (created_at)');
            DB::statement("CREATE TABLE conversation_messages_2026_01 PARTITION OF conversation_messages FOR VALUES FROM ('2026-01-01 00:00:00+00') TO ('2026-02-01 00:00:00+00')");
            DB::statement("CREATE TABLE conversation_messages_2026_02 PARTITION OF conversation_messages FOR VALUES FROM ('2026-02-01 00:00:00+00') TO ('2026-03-01 00:00:00+00')");
        }
    }

    protected function tearDown(): void
    {
        if ('pgsql' === DB::connection()->getDriverName()) {
            DB::statement("SET search_path TO {$this->originalSearchPath}");

            foreach (self::SCHEMAS as $schema) {
                DB::statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
            }
        }

        parent::tearDown();
    }

    public function test_listing_and_dropping_are_scoped_to_the_current_schema(): void
    {
        DB::statement('SET search_path TO cr_test_a');

        $manager = new ConversationPartitionManager(app('db'));

        $this->assertSame(
            ['conversation_messages_2026_01', 'conversation_messages_2026_02'],
            $manager->listMonthlyPartitions(),
        );

        $manager->dropPartition('conversation_messages_2026_01');

        $this->assertSame(['conversation_messages_2026_02'], $manager->listMonthlyPartitions());

        DB::statement('SET search_path TO cr_test_b');

        $this->assertSame(
            ['conversation_messages_2026_01', 'conversation_messages_2026_02'],
            $manager->listMonthlyPartitions(),
        );
    }
}
