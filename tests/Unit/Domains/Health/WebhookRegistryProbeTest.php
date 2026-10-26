<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Health;

use App\Domains\Health\Enums\HealthState;
use App\Domains\Health\Probes\WebhookRegistryProbe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

final class WebhookRegistryProbeTest extends TestCase
{
    public function test_up_when_the_cache_matches_the_registry(): void
    {
        $this->registryRows([$this->row('hash-1')]);
        Redis::shouldReceive('get')->with('webhook:hash-1')->andReturn($this->payload());
        Redis::shouldReceive('keys')->andReturn(['webhook:hash-1']);

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Up, $result->state);
        $this->assertSame(['missing' => 0, 'stale' => 0, 'orphaned' => 0], $result->details);
    }

    public function test_down_with_counts_when_the_cache_drifted(): void
    {
        $this->registryRows([$this->row('hash-1')]);
        Redis::shouldReceive('get')->with('webhook:hash-1')->andReturn(null);
        Redis::shouldReceive('keys')->andReturn(['webhook:hash-orphan']);

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Down, $result->state);
        $this->assertSame(['missing' => 1, 'stale' => 0, 'orphaned' => 1], $result->details);
        $this->assertStringContainsString('ops:webhook-registry-health --repair', $result->reason);
    }

    public function test_unknown_when_the_registry_cannot_be_read(): void
    {
        DB::shouldReceive('connection')->andThrow(new RuntimeException('landlord down'));

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Unknown, $result->state);
    }

    private function probe(): WebhookRegistryProbe
    {
        return $this->app->make(WebhookRegistryProbe::class);
    }

    /**
     * @param list<object> $rows
     */
    private function registryRows(array $rows): void
    {
        DB::shouldReceive('connection')->with('landlord')->andReturnSelf();
        DB::shouldReceive('table')->with('webhook_registry')->andReturnSelf();
        DB::shouldReceive('get')->andReturn(collect($rows));
    }

    private function row(string $hash): object
    {
        return (object) [
            'webhook_public_hash' => $hash,
            'tenant_id'           => 'tenant-1',
            'assistant_id'        => 'assist-1',
            'channel_id'          => 'chan-1',
            'schema'              => 'tenant_1',
            'platform'            => 'telegram',
            'secret_token'        => 'secret',
        ];
    }

    private function payload(): string
    {
        return json_encode([
            'tenant_id'    => 'tenant-1',
            'assistant_id' => 'assist-1',
            'channel_id'   => 'chan-1',
            'schema'       => 'tenant_1',
            'channel'      => 'telegram',
            'secret_token' => 'secret',
        ], JSON_THROW_ON_ERROR);
    }
}
