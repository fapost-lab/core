<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\DTOs\FlowRunOrigin;
use App\Domains\Flow\Orchestration\RerunFireKey;
use App\Domains\Flow\Webhook\WebhookFireKey;
use PHPUnit\Framework\TestCase;

final class RerunFireKeyTest extends TestCase
{
    private const string SOURCE = '019a6b1e-7c2d-7f00-8e11-3c4d5e6f7a8b';

    public function test_a_key_names_its_source_and_attempt_and_reads_back_its_source(): void
    {
        $key = RerunFireKey::for(self::SOURCE, 2);

        $this->assertSame('rerun:' . self::SOURCE . ':2', $key);
        $this->assertStringStartsWith(RerunFireKey::prefixOf(self::SOURCE), $key);
        $this->assertTrue(RerunFireKey::isRerun($key));
        $this->assertSame(self::SOURCE, RerunFireKey::sourceId($key));
    }

    public function test_a_re_run_of_a_re_run_reads_back_its_direct_source(): void
    {
        $this->assertSame(self::SOURCE, RerunFireKey::sourceId(RerunFireKey::for(self::SOURCE, 10)));
    }

    public function test_a_key_reads_back_its_attempt(): void
    {
        $this->assertSame(12, RerunFireKey::attemptOf(RerunFireKey::for(self::SOURCE, 12)));

        foreach (['wh:d-1', 'rerun:' . self::SOURCE . ':0', 'rerun:' . self::SOURCE . ':x', 'rerun:' . self::SOURCE . ':'] as $key) {
            $this->assertNull(RerunFireKey::attemptOf($key), $key);
        }
    }

    public function test_no_other_trigger_s_key_is_a_re_run_s(): void
    {
        foreach ([WebhookFireKey::fromDeliveryId('rerun:' . self::SOURCE . ':1'), '01JD3X5Y6Z7A8B9C0D1E2F3G4H', 'rerun:', 'rerun::1'] as $key) {
            $this->assertNull(RerunFireKey::sourceId($key), $key);
        }

        $this->assertFalse(WebhookFireKey::isWebhookFireKey(RerunFireKey::for(self::SOURCE, 1)));
    }

    public function test_the_longest_key_fits_the_column(): void
    {
        $this->assertLessThanOrEqual(FlowRunOrigin::MAX_FIRE_KEY_LENGTH, mb_strlen(RerunFireKey::for(self::SOURCE, PHP_INT_MAX)));
    }
}
