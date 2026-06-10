<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\WebhookRegistryWriterInterface;
use App\Domains\Tenancy\Services\WebhookRegistryHealthChecker;
use App\Domains\Tenancy\ValueObjects\WebhookRegistryHealthReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery\MockInterface;
use Tests\TestCase;

final class WebhookRegistryHealthCheckerTest extends TestCase
{
    public function test_check_reports_consistent_registry_as_healthy(): void
    {
        $this->fakeLandlordRows([$this->row('hash-1')]);

        Redis::shouldReceive('get')->once()->with('webhook:hash-1')->andReturn($this->payloadJson());
        Redis::shouldReceive('keys')->once()->with('webhook:*')->andReturn(['webhook:hash-1']);

        $report = $this->checker()->check();

        $this->assertTrue($report->isHealthy());
    }

    public function test_check_detects_missing_stale_and_orphaned_entries(): void
    {
        $this->fakeLandlordRows([
            $this->row('hash-missing'),
            $this->row('hash-stale'),
        ]);

        Redis::shouldReceive('get')->once()->with('webhook:hash-missing')->andReturn(null);
        Redis::shouldReceive('get')->once()->with('webhook:hash-stale')
            ->andReturn($this->payloadJson(['secret_token' => 'rotated-elsewhere']));
        // Raw keys carry the connection prefix — the checker must still extract hashes.
        Redis::shouldReceive('keys')->once()->with('webhook:*')->andReturn([
            'laravel_database_webhook:hash-stale',
            'laravel_database_webhook:hash-orphan',
        ]);

        $report = $this->checker()->check();

        $this->assertSame(['hash-missing'], $report->missing);
        $this->assertSame(['hash-stale'], $report->stale);
        $this->assertSame(['hash-orphan'], $report->orphaned);
        $this->assertFalse($report->isHealthy());
    }

    public function test_repair_rewrites_drifted_entries_and_deletes_orphans(): void
    {
        DB::shouldReceive('connection')->with('landlord')->andReturnSelf();
        DB::shouldReceive('table')->with('webhook_registry')->andReturnSelf();
        DB::shouldReceive('whereIn')
            ->once()
            ->with('webhook_public_hash', ['hash-missing', 'hash-stale'])
            ->andReturnSelf();
        DB::shouldReceive('get')->once()->andReturn(collect([
            $this->row('hash-missing'),
            $this->row('hash-stale'),
        ]));

        Redis::shouldReceive('del')->once()->with('webhook:hash-orphan');

        $writer = $this->mock(WebhookRegistryWriterInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('write')->twice()->withArgs(
                fn (string $hash, TenantInterface $tenant): bool => in_array($hash, ['hash-missing', 'hash-stale'], true)
                    && 'tenant-1' === $tenant->getId()
                    && 'tenant_1' === $tenant->getSchemaName(),
            );
        });

        $checker = new WebhookRegistryHealthChecker($writer);

        $checker->repair(new WebhookRegistryHealthReport(
            missing: ['hash-missing'],
            stale: ['hash-stale'],
            orphaned: ['hash-orphan'],
        ));
    }

    public function test_repair_is_a_noop_for_healthy_report(): void
    {
        $checker = $this->checker();

        $checker->repair(new WebhookRegistryHealthReport(missing: [], stale: [], orphaned: []));

        $this->addToAssertionCount(1);
    }

    private function checker(): WebhookRegistryHealthChecker
    {
        return new WebhookRegistryHealthChecker(
            $this->mock(WebhookRegistryWriterInterface::class),
        );
    }

    /**
     * @param  list<object>  $rows
     */
    private function fakeLandlordRows(array $rows): void
    {
        DB::shouldReceive('connection')->with('landlord')->andReturnSelf();
        DB::shouldReceive('table')->with('webhook_registry')->andReturnSelf();
        DB::shouldReceive('get')->once()->andReturn(collect($rows));
    }

    private function row(string $hash): object
    {
        return (object)[
            'webhook_public_hash' => $hash,
            'tenant_id'           => 'tenant-1',
            'assistant_id'        => 'assist-1',
            'channel_id'          => 'chan-1',
            'schema'              => 'tenant_1',
            'platform'            => 'telegram',
            'secret_token'        => 'secret',
        ];
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function payloadJson(array $overrides = []): string
    {
        return json_encode(array_merge([
            'tenant_id'    => 'tenant-1',
            'assistant_id' => 'assist-1',
            'channel_id'   => 'chan-1',
            'schema'       => 'tenant_1',
            'channel'      => 'telegram',
            'secret_token' => 'secret',
        ], $overrides), JSON_THROW_ON_ERROR);
    }
}
