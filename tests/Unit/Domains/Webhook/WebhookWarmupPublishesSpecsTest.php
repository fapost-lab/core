<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use Illuminate\Support\Facades\Redis;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Warmup is the documented way to rebuild ingress state in Redis after a flush.
 *
 * Ingress specs are the half nothing else can rebuild: the application reads them
 * from the adapters directly, so their absence is invisible in PHP and only
 * disables verification for the external gateway. If warmup skipped them, a
 * recovered-looking system would still be broken for gateway traffic.
 */
final class WebhookWarmupPublishesSpecsTest extends TestCase
{
    public function test_warmup_republishes_ingress_specs(): void
    {
        $this->withoutTenants();

        Redis::shouldReceive('set')
            ->once()
            ->with('ingress:spec:telegram', \Mockery::type('string'))
            ->andReturnTrue();

        Redis::shouldReceive('del')->andReturn(1);

        $this->artisan('ops:webhook-warmup', ['--all' => true])->assertSuccessful();
    }

    /**
     * Registry warmup is the more urgent half, so a spec failure must be reported
     * rather than abort the run before the registry is repopulated.
     */
    public function test_spec_publication_failure_does_not_abort_the_warmup(): void
    {
        $this->withoutTenants();

        Redis::shouldReceive('set')->andThrow(new RuntimeException('redis unreachable'));
        Redis::shouldReceive('del')->andReturn(1);

        $this->artisan('ops:webhook-warmup', ['--all' => true])->assertSuccessful();
    }

    private function withoutTenants(): void
    {
        $this->mock(TenantRepositoryInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findAllActive')->andReturn([]);
        });
    }
}
