<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Domains\Assistant\Contracts\AssistantServiceInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\Services\UnlimitedTenantLimits;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use LogicException;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeTenantLimits;

/**
 * The assistant limit is checked in AssistantService::create().
 */
final class AssistantLimitTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_service_creates_until_the_limit_then_throws(): void
    {
        $this->limitAssistants(1);

        $this->inTenant(function (): void {
            $tenant  = $this->tenant();
            $service = app(AssistantServiceInterface::class);

            $service->create($tenant, ['name' => 'First']);

            try {
                $service->create($tenant, ['name' => 'Second']);
                $this->fail('Expected RecordLimitReachedException.');
            } catch (RecordLimitReachedException $e) {
                $this->assertSame('Assistants limit reached: 1 of 1.', $e->getMessage());
            }

            $this->assertSame(1, Assistant::query()->count());
        });
    }

    public function test_default_has_no_limit(): void
    {
        $this->app->instance(TenantLimitsInterface::class, new UnlimitedTenantLimits());

        $this->inTenant(function (): void {
            $tenant  = $this->tenant();
            $service = app(AssistantServiceInterface::class);

            $service->create($tenant, ['name' => 'A']);
            $service->create($tenant, ['name' => 'B']);

            $this->assertSame(2, Assistant::query()->count());
        });
    }

    public function test_create_refuses_a_tenant_other_than_the_current_context(): void
    {
        $this->inTenant(function (): void {
            $other = new RuntimeTenant('00000000-0000-0000-0000-0000000000ff', 'other');

            $this->expectException(LogicException::class);

            app(AssistantServiceInterface::class)->create($other, ['name' => 'Wrong']);
        });
    }

    public function test_limit_zero_refuses_the_first_assistant(): void
    {
        $this->limitAssistants(0);

        $this->inTenant(function (): void {
            $tenant = $this->tenant();
            $this->expectException(RecordLimitReachedException::class);

            app(AssistantServiceInterface::class)->create($tenant, ['name' => 'First']);
        });
    }

    public function test_downgrade_keeps_existing_assistants_but_forbids_new_ones(): void
    {
        $this->inTenant(function (): void {
            $tenant  = $this->tenant();
            $service = app(AssistantServiceInterface::class);
            $service->create($tenant, ['name' => 'A']);
            $service->create($tenant, ['name' => 'B']);

            $this->limitAssistants(1);
            $service = app(AssistantServiceInterface::class);

            try {
                $service->create($tenant, ['name' => 'C']);
                $this->fail('Expected RecordLimitReachedException.');
            } catch (RecordLimitReachedException) {
                $this->assertSame(2, Assistant::query()->count());
            }
        });
    }

    private function limitAssistants(?int $limit): FakeTenantLimits
    {
        $limits = new FakeTenantLimits(['assistants' => $limit]);

        $this->app->instance(TenantLimitsInterface::class, $limits);

        return $limits;
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->firstOrFail();
    }

    /**
     * @param  callable(): void  $callback
     */
    private function inTenant(callable $callback): void
    {
        $this->app->make(TenantSwitcher::class)->runForTenant($this->tenant(), $callback);
    }
}
