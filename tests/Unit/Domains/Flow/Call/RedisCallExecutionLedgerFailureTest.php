<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Call;

use App\Domains\Flow\Call\RedisCallExecutionLedger;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use Fapost\Foundation\Flow\Call\CallResult;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\TestCase;

/**
 * An unreachable Redis never blocks a call: the ledger answers as if it had no record.
 */
final class RedisCallExecutionLedgerFailureTest extends TestCase
{
    public function test_a_failing_connection_means_no_record_attempt_one_and_no_exception(): void
    {
        $redis = new class () implements RedisFactory {
            public function connection($name = null): void
            {
                throw new RuntimeException('redis down');
            }
        };
        $settings = new class () implements PlatformSettingsReaderInterface {
            public function integer(string $key): int
            {
                return 192;
            }

            public function boolean(string $key): bool
            {
                return false;
            }

            public function text(string $key): string
            {
                return '';
            }
        };
        $ledger = new RedisCallExecutionLedger($redis, $settings, new NullLogger());

        $this->assertNull($ledger->completed('t', 'k'));
        $this->assertSame(1, $ledger->startAttempt('t', 'k'));

        $ledger->complete('t', 'k', CallResult::ok(['a' => 1]));
        $this->assertNull($ledger->completed('t', 'k'));
    }
}
