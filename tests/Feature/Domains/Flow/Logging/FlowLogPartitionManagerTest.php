<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Logging;

use App\Domains\Flow\Logging\FlowLogPartitionManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;
use Tests\Support\RecordingConnectionResolver;

final class FlowLogPartitionManagerTest extends FeatureTestCase
{
    public function test_the_partition_manager_resolves_its_connection_when_it_works(): void
    {
        $resolver = new RecordingConnectionResolver($this->app->make('db'));
        $this->app->instance(ConnectionResolverInterface::class, $resolver);
        $this->app->forgetScopedInstances();

        $manager = $this->app->make(FlowLogPartitionManager::class);

        $this->assertSame([], $resolver->resolved, 'A connection captured when the manager is built would outlive a tenant switch.');

        $manager->ensureMonthlyPartition(CarbonImmutable::now());

        $this->assertNotEmpty($resolver->resolved);
        $this->assertSame([DB::getDefaultConnection()], array_values(array_unique($resolver->resolved)));
    }
}
