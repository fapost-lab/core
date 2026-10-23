<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Health;

use App\Domains\Health\Contracts\HealthProbe;
use App\Domains\Health\Enums\HealthState;
use App\Domains\Health\Probes\GatewayProbe;
use App\Domains\Health\Probes\ReverbProbe;
use App\Domains\Health\Probes\WebhookRegistryProbe;
use App\Domains\Health\Services\HealthService;
use App\Domains\Health\ValueObjects\ProbeResult;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Carbon;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class HealthServiceTest extends TestCase
{
    public function test_the_container_service_covers_reverb_gateway_and_the_registry(): void
    {
        $report = $this->app->make(HealthService::class)->report();

        $this->assertSame(
            [ReverbProbe::KEY, GatewayProbe::KEY, WebhookRegistryProbe::KEY],
            array_keys($report->results),
        );
    }

    public function test_the_service_is_not_kept_across_scopes(): void
    {
        $first = $this->app->make(HealthService::class);

        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, $this->app->make(HealthService::class));
    }

    public function test_an_answer_is_cached_for_the_probes_ttl(): void
    {
        Carbon::setTestNow('2026-10-22 12:00:00');
        $probe   = $this->countingProbe(5);
        $service = $this->service($probe);

        $service->report();
        $service->report();
        $this->assertSame(1, $probe->calls);

        Carbon::setTestNow('2026-10-22 12:00:06');
        $service->report();
        $this->assertSame(2, $probe->calls);

        Carbon::setTestNow();
    }

    public function test_each_probe_keeps_its_own_ttl(): void
    {
        Carbon::setTestNow('2026-10-22 12:00:00');
        $fast    = $this->countingProbe(5, 'fast');
        $slow    = $this->countingProbe(60, 'slow');
        $service = $this->service($fast, $slow);

        $service->report();
        Carbon::setTestNow('2026-10-22 12:00:10');
        $service->report();

        $this->assertSame(2, $fast->calls);
        $this->assertSame(1, $slow->calls);

        Carbon::setTestNow();
    }

    public function test_a_cached_answer_keeps_its_state_and_details(): void
    {
        $service = $this->service($this->countingProbe(5));

        $service->report();
        $result = $service->probe('counting');

        $this->assertNotNull($result);
        $this->assertSame(HealthState::Up, $result->state);
        $this->assertSame(['n' => 1], $result->details);
    }

    public function test_a_probe_that_throws_is_unknown_not_healthy(): void
    {
        $service = $this->service(new class () implements HealthProbe {
            public function key(): string
            {
                return 'broken';
            }

            public function cacheTtl(): int
            {
                return 5;
            }

            public function check(): ProbeResult
            {
                throw new RuntimeException('secret-looking detail');
            }
        });

        $result = $service->probe('broken');

        $this->assertNotNull($result);
        $this->assertSame(HealthState::Unknown, $result->state);
        $this->assertStringNotContainsString('secret-looking detail', $result->reason);
    }

    public function test_an_unavailable_cache_means_a_live_probe(): void
    {
        $probe  = $this->countingProbe(5);
        $broken = Mockery::mock(CacheContract::class);
        $broken->shouldReceive('get')->andThrow(new RuntimeException('cache down'));
        $broken->shouldReceive('put')->andThrow(new RuntimeException('cache down'));
        $service = new HealthService([$probe], $broken);

        $this->assertSame(HealthState::Up, $service->report()->get('counting')?->state);
    }

    public function test_an_unknown_key_has_no_result(): void
    {
        $this->assertNull($this->service($this->countingProbe(5))->probe('nope'));
    }

    private function service(HealthProbe ...$probes): HealthService
    {
        return new HealthService($probes, new Repository(new ArrayStore()));
    }

    private function countingProbe(int $ttl, string $key = 'counting'): object
    {
        return new class ($ttl, $key) implements HealthProbe {
            public int $calls = 0;

            public function __construct(private readonly int $ttl, private readonly string $key)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function cacheTtl(): int
            {
                return $this->ttl;
            }

            public function check(): ProbeResult
            {
                $this->calls++;

                return ProbeResult::up($this->key, 'ok', ['n' => $this->calls]);
            }
        };
    }
}
