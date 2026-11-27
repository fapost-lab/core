<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Conversation;

use App\Console\Commands\CreateConversationMessagePartitionsCommand;
use App\Domains\Conversation\Store\Postgres\ConversationPartitionManager;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Listing and dropping partitions against a real PostgreSQL: the default test
 * run uses sqlite, so this skips there. Run with a pgsql DB_CONNECTION.
 * Creates two throwaway schemas holding identically named partitions, the way
 * every tenant schema does, and proves the manager only sees the current one
 * and the create-partition command fills each schema ahead of its writes.
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
        CarbonImmutable::setTestNow();
        Mockery::close();

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

    public function test_the_command_creates_the_current_and_next_month_in_every_tenant_schema_idempotently(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-28 23:30:00 UTC'));

        $this->bindCommandOver(self::SCHEMAS);

        $this->artisan('conversations:create-partition')->assertSuccessful();
        $this->artisan('conversations:create-partition')->assertSuccessful();

        $manager = new ConversationPartitionManager(app('db'));

        foreach (self::SCHEMAS as $schema) {
            DB::statement("SET search_path TO {$schema}");

            $this->assertSame(
                ['conversation_messages_2026_01', 'conversation_messages_2026_02', 'conversation_messages_2026_03'],
                $manager->listMonthlyPartitions(),
            );

            DB::insert(
                'INSERT INTO conversation_messages (id, created_at) VALUES (?, ?)',
                ['01890000-0000-7000-8000-000000000001', '2026-03-01 00:00:00+00'],
            );

            $this->assertSame(
                1,
                (int)DB::selectOne('SELECT count(*) AS total FROM conversation_messages_2026_03')->total,
            );
        }
    }

    /**
     * Binds the command over tenants whose "schema switch" is a search_path change, so the real
     * DDL runs once per throwaway schema.
     *
     * @param array<int, string> $schemas
     */
    private function bindCommandOver(array $schemas): void
    {
        $tenants = array_map(static function (string $schema): TenantInterface {
            $tenant = Mockery::mock(TenantInterface::class);
            $tenant->shouldReceive('getSlug')->andReturn($schema);
            $tenant->shouldReceive('getId')->andReturn($schema);

            return $tenant;
        }, $schemas);

        $repository = Mockery::mock(TenantRepositoryInterface::class);
        $repository->shouldReceive('findAllActive')->andReturn($tenants);

        $tenantDatabase = Mockery::mock(TenantDatabaseManagerInterface::class);
        $tenantDatabase->shouldReceive('switchTo')->andReturnUsing(static function (TenantInterface $tenant): void {
            DB::statement("SET search_path TO {$tenant->getSlug()}");
        });
        $tenantDatabase->shouldReceive('restore')->andReturnUsing(function (): void {
            DB::statement("SET search_path TO {$this->originalSearchPath}");
        });

        $permissions = Mockery::mock(PermissionRegistrar::class);
        $permissions->shouldReceive('clearPermissionsCollection');

        $this->app->instance(
            CreateConversationMessagePartitionsCommand::class,
            new CreateConversationMessagePartitionsCommand(
                $repository,
                new TenantSwitcher(new TenantContext(), $tenantDatabase, $permissions),
                app('db'),
            ),
        );
    }
}
