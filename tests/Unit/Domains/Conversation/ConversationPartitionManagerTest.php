<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Conversation;

use App\Domains\Conversation\Store\Postgres\ConversationPartitionManager;
use InvalidArgumentException;
use Tests\TestCase;

final class ConversationPartitionManagerTest extends TestCase
{
    public function test_it_lists_nothing_and_drops_nothing_on_a_non_postgres_connection(): void
    {
        $manager = new ConversationPartitionManager(app('db'));

        $this->assertSame([], $manager->listMonthlyPartitions());

        $manager->dropPartition('conversation_messages_2020_01');
        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_names_outside_the_partition_pattern(): void
    {
        $manager = new ConversationPartitionManager(app('db'));

        $this->expectException(InvalidArgumentException::class);

        $manager->dropPartition('users; DROP TABLE users');
    }
}
