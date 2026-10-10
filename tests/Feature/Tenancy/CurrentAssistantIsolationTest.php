<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Tests\Feature\FeatureTestCase;

/**
 * A worker runs many jobs in one process: the assistant one job set must not be visible to the next.
 */
final class CurrentAssistantIsolationTest extends FeatureTestCase
{
    public function test_assistant_set_in_one_tenant_run_does_not_leak_into_the_next_run(): void
    {
        $first  = Assistant::factory()->create();
        $second = Assistant::factory()->create();
        $tenant = Tenant::query()->firstOrFail();

        $switcher = $this->app->make(TenantSwitcher::class);
        $current  = $this->app->make(CurrentAssistantInterface::class);

        $seenByFirst = $switcher->runForTenant($tenant, function () use ($current, $first): string {
            $this->assertFalse($current->isResolved());
            $current->set($first);

            return $current->get()->getKey();
        });

        $this->assertFalse($current->isResolved());

        $seenBySecond = $switcher->runForTenant($tenant, function () use ($current, $second): string {
            $this->assertFalse($current->isResolved());
            $current->set($second);

            return $current->get()->getKey();
        });

        $this->assertSame($first->getKey(), $seenByFirst);
        $this->assertSame($second->getKey(), $seenBySecond);
        $this->assertFalse($current->isResolved());
    }

    public function test_a_nested_run_hides_the_callers_assistant_and_gives_it_back_on_exit(): void
    {
        $outer  = Assistant::factory()->create();
        $inner  = Assistant::factory()->create();
        $tenant = Tenant::query()->firstOrFail();

        $switcher = $this->app->make(TenantSwitcher::class);
        $current  = $this->app->make(CurrentAssistantInterface::class);

        $current->set($outer);

        $switcher->runForTenant($tenant, function () use ($switcher, $tenant, $current, $inner): void {
            $this->assertFalse($current->isResolved());
            $current->set($inner);

            $switcher->runForTenant($tenant, function () use ($current): void {
                $this->assertFalse($current->isResolved());
            });

            $this->assertSame($inner->getKey(), $current->get()->getKey());
        });

        $this->assertSame($outer->getKey(), $current->get()->getKey());
    }
}
