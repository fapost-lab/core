<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Services\WebhookRegistryWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

final class WebhookRegistryWriterTest extends TestCase
{
    private WebhookRegistryWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = $this->app->make(WebhookRegistryWriter::class);
    }

    public function test_write_persists_to_redis(): void
    {
        [$tenant] = $this->makeTenantAndArgs();

        Redis::shouldReceive('set')
            ->once()
            ->withArgs(function (string $key, string $json): bool {
                if ('webhook:abc-hash' !== $key) {
                    return false;
                }
                $data = json_decode($json, true);

                return 'tenant-1' === $data['tenant_id']
                    && 'assist-1' === $data['assistant_id']
                    && 'chan-1' === $data['channel_id']
                    && 'tenant_1' === $data['schema']
                    && 'telegram' === $data['channel']
                    && 'secret' === $data['secret_token'];
            });

        DB::shouldReceive('connection')->with('landlord')->andReturnSelf();
        DB::shouldReceive('table')->with('webhook_registry')->andReturnSelf();
        DB::shouldReceive('upsert')->once()->andReturn(1);

        $this->writer->write('abc-hash', $tenant, 'assist-1', 'chan-1', 'telegram', 'secret');
    }

    public function test_write_persists_to_landlord_db(): void
    {
        [$tenant] = $this->makeTenantAndArgs();

        Redis::shouldReceive('set')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('landlord')->andReturnSelf();
        DB::shouldReceive('table')->with('webhook_registry')->andReturnSelf();
        DB::shouldReceive('upsert')
            ->once()
            ->withArgs(function (array $rows): bool {
                $row = $rows[0];

                return 'abc-hash' === $row['webhook_public_hash']
                    && 'tenant-1' === $row['tenant_id']
                    && 'assist-1' === $row['assistant_id']
                    && 'chan-1' === $row['channel_id']
                    && 'tenant_1' === $row['schema']
                    && 'telegram' === $row['platform']
                    && 'secret' === $row['secret_token'];
            });

        $this->writer->write('abc-hash', $tenant, 'assist-1', 'chan-1', 'telegram', 'secret');
    }

    public function test_delete_removes_from_redis_and_landlord(): void
    {
        Redis::shouldReceive('del')
            ->once()
            ->with('webhook:abc-hash');

        DB::shouldReceive('connection')->with('landlord')->andReturnSelf();
        DB::shouldReceive('table')->with('webhook_registry')->andReturnSelf();
        DB::shouldReceive('where')->with('webhook_public_hash', 'abc-hash')->andReturnSelf();
        DB::shouldReceive('delete')->once()->andReturn(1);

        $this->writer->delete('abc-hash');
    }

    /**
     * @return array{TenantInterface}
     */
    private function makeTenantAndArgs(): array
    {
        $tenant = Mockery::mock(TenantInterface::class);
        $tenant->shouldReceive('getId')->andReturn('tenant-1');
        $tenant->shouldReceive('getSchemaName')->andReturn('tenant_1');

        return [$tenant];
    }
}
