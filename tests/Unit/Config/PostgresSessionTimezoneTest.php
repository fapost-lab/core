<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Timestamps are written and windows are queried without an offset, so every Postgres connection must read them in UTC
 * whatever TimeZone the database server defaults to.
 */
final class PostgresSessionTimezoneTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function postgresConnections(): array
    {
        return [
            'pgsql'    => ['pgsql'],
            'tenant'   => ['tenant'],
            'landlord' => ['landlord'],
        ];
    }

    #[DataProvider('postgresConnections')]
    public function test_postgres_connection_pins_the_session_timezone_to_utc(string $connection): void
    {
        $this->assertSame('UTC', config("database.connections.{$connection}.timezone"));
    }

    public function test_application_timezone_is_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
    }
}
