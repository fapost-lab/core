<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Onboarding\Services\OnboardingProgress;
use App\Domains\Onboarding\Services\OnboardingState;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantStatus;
use App\Infrastructure\Onboarding\CoreTenantOnboarding;
use Fapost\Foundation\Tenancy\Contracts\TenantOnboardingInterface;
use Fapost\Foundation\Tenancy\Exceptions\TenantOnboardingException;
use Tests\Feature\FeatureTestCase;

/**
 * The operator's side of the wizard: the Foundation contract and the command behind it.
 */
final class CoreTenantOnboardingTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_the_container_resolves_the_contract_to_the_core_implementation(): void
    {
        $this->assertInstanceOf(CoreTenantOnboarding::class, $this->app->make(TenantOnboardingInterface::class));
    }

    public function test_restart_holds_the_tenant_again_and_forgets_confirmations_but_not_the_assistant(): void
    {
        Assistant::factory()->create();
        $state = $this->app->make(OnboardingState::class);
        $state->start();
        $state->confirm('languages');
        $state->complete();
        $contract = $this->app->make(TenantOnboardingInterface::class);

        $this->assertFalse($contract->isPending(self::TENANT_ID));

        $contract->restart(self::TENANT_ID);

        $this->assertTrue($contract->isPending(self::TENANT_ID));
        $this->assertSame([], $state->confirmedSteps());
        $steps = collect($this->app->make(OnboardingProgress::class)->report()->steps)
            ->mapWithKeys(static fn ($progress): array => [$progress->step->key() => $progress->complete]);
        $this->assertSame(['languages' => false, 'first_assistant' => true, 'channel' => false, 'team' => false], $steps->all());
    }

    public function test_restart_from_outside_every_tenant_leaves_the_callers_context_as_it_was(): void
    {
        $context = $this->app->make(TenantContextInterface::class);
        $before  = $context->isResolved();

        $this->app->make(TenantOnboardingInterface::class)->restart(self::TENANT_ID);

        $this->assertSame($before, $context->isResolved());
        $this->assertTrue($this->app->make(OnboardingState::class)->isPending());
    }

    public function test_an_unknown_malformed_or_inactive_tenant_is_refused(): void
    {
        $contract = $this->app->make(TenantOnboardingInterface::class);
        $pending  = Tenant::on('landlord')->create(['slug' => 'later', 'schema_name' => 'tenant_later', 'status' => TenantStatus::Pending, 'config' => []]);

        foreach (['00000000-0000-0000-0000-0000000000aa', 'not-an-id'] as $id) {
            try {
                $contract->restart($id);
                $this->fail('Expected the tenant to be refused.');
            } catch (TenantOnboardingException $exception) {
                $this->assertSame($id, $exception->tenantId);
                $this->assertStringContainsString('was not found', $exception->getMessage());
            }
        }

        try {
            $contract->isPending((string) $pending->getKey());
            $this->fail('Expected the pending tenant to be refused.');
        } catch (TenantOnboardingException $exception) {
            $this->assertStringContainsString('is not active', $exception->getMessage());
        }

        $this->assertFalse($this->app->make(OnboardingState::class)->isPending());
    }

    public function test_the_command_asks_first_and_restarts_by_slug(): void
    {
        $this->artisan('tenants:onboarding:restart', ['tenant' => 'main'])
            ->expectsConfirmation('The staff of "main" will be held in the setup wizard until they finish its required steps. Restart it?', 'no')
            ->expectsOutputToContain('Nothing was changed.')
            ->assertSuccessful();
        $this->assertFalse($this->app->make(OnboardingState::class)->isPending());

        $this->artisan('tenants:onboarding:restart', ['tenant' => 'main'])
            ->expectsConfirmation('The staff of "main" will be held in the setup wizard until they finish its required steps. Restart it?', 'yes')
            ->assertSuccessful();
        $this->assertTrue($this->app->make(OnboardingState::class)->isPending());
    }

    public function test_the_command_by_id_with_force_and_the_refusals(): void
    {
        $this->artisan('tenants:onboarding:restart', ['tenant' => mb_strtoupper(self::TENANT_ID), '--force' => true])->assertSuccessful();
        $this->assertTrue($this->app->make(OnboardingState::class)->isPending());

        $this->artisan('tenants:onboarding:restart', ['tenant' => 'nobody', '--force' => true])
            ->expectsOutputToContain('No tenant "nobody".')
            ->assertFailed();

        Tenant::on('landlord')->create(['slug' => 'later', 'schema_name' => 'tenant_later', 'status' => TenantStatus::Pending, 'config' => []]);
        $this->artisan('tenants:onboarding:restart', ['tenant' => 'later', '--force' => true])
            ->expectsOutputToContain('is not active')
            ->assertFailed();
    }
}
