<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * {@see ConnectionResolverInterface} passing every call to the real one, for tests: the names of the connections it
 * hands out are recorded, so a test can tell a connection taken when a service is built from one taken when it writes.
 */
final class RecordingConnectionResolver implements ConnectionResolverInterface
{
    /**
     * Names of the connections handed out, in order.
     *
     * @var list<string>
     */
    public array $resolved = [];

    public function __construct(private readonly ConnectionResolverInterface $db)
    {
    }

    public function connection($name = null): ConnectionInterface
    {
        $connection       = $this->db->connection($name);
        $this->resolved[] = (string) $connection->getName();

        return $connection;
    }

    public function getDefaultConnection(): string
    {
        return $this->db->getDefaultConnection();
    }

    public function setDefaultConnection($name): void
    {
        $this->db->setDefaultConnection($name);
    }
}
