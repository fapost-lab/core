<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Shared\Infrastructure;

use App\Domains\Shared\Infrastructure\PostgresMonthlyPartitions;
use Closure;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

final class PostgresMonthlyPartitionsTest extends TestCase
{
    public function test_drop_runs_in_a_transaction_with_a_lock_timeout_before_the_qualified_drop(): void
    {
        $calls      = [];
        $connection = Mockery::mock(ConnectionInterface::class);

        $connection->shouldReceive('selectOne')->once()->andReturn((object)['schema_name' => 'tenant_a']);
        $connection->shouldReceive('transaction')->once()->andReturnUsing(
            static function (Closure $callback) use (&$calls): void {
                $calls[] = 'BEGIN';
                $callback();
                $calls[] = 'COMMIT';
            }
        );
        $connection->shouldReceive('statement')->twice()->andReturnUsing(
            static function (string $sql) use (&$calls): bool {
                $calls[] = $sql;

                return true;
            }
        );

        (new PostgresMonthlyPartitions())->drop($connection, 'flow_logs', 'flow_logs_2026_01');

        $this->assertSame([
            'BEGIN',
            "SET LOCAL lock_timeout = '5s'",
            'DROP TABLE IF EXISTS "tenant_a"."flow_logs_2026_01"',
            'COMMIT',
        ], $calls);
    }

    public function test_drop_rejects_a_name_outside_the_parent_tables_pattern_without_touching_the_connection(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldNotReceive('selectOne', 'transaction', 'statement');

        $this->expectException(InvalidArgumentException::class);

        (new PostgresMonthlyPartitions())->drop($connection, 'flow_logs', 'conversation_messages_2026_01');
    }

    public function test_drop_rejects_an_unusable_schema_name(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('selectOne')->once()->andReturn((object)['schema_name' => 'Bad"Schema']);
        $connection->shouldNotReceive('transaction', 'statement');

        $this->expectException(InvalidArgumentException::class);

        (new PostgresMonthlyPartitions())->drop($connection, 'flow_logs', 'flow_logs_2026_01');
    }

    public function test_list_binds_the_parent_table_and_the_partition_pattern(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')->once()->withArgs(
            static fn (string $sql, array $bindings): bool => str_contains($sql, 'current_schema()')
                && ['conversation_messages', '^conversation_messages_[0-9]{4}_[0-9]{2}$'] === $bindings
        )->andReturn([(object)['partition_name' => 'conversation_messages_2026_01']]);

        $this->assertSame(
            ['conversation_messages_2026_01'],
            (new PostgresMonthlyPartitions())->list($connection, 'conversation_messages'),
        );
    }
}
