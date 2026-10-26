<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Onboarding\Enums\OnboardingOrigin;
use App\Domains\Onboarding\Enums\OnboardingStatus;
use App\Domains\Onboarding\Services\OnboardingChecklist;
use App\Domains\Onboarding\Services\OnboardingState;
use App\Domains\Onboarding\Services\OnboardingWizard;
use App\Domains\Onboarding\Settings\OnboardingSettings;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Services\PlatformSupportUserService;
use App\Domains\Staff\Support\SupportAccessSession;
use Database\Seeders\TenantAclSeeder;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;

/**
 * The setup checklist: who sees it, in what order, and how a user hides it and gets it back.
 */
final class OnboardingChecklistTest extends FeatureTestCase
{
    use InteractsWithOnboarding;
    use InteractsWithOnboardingSteps;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_a_tenant_in_the_wizard_has_no_checklist(): void
    {
        $this->registerStep(OnboardingOrigin::Package, 'extra', false, static fn (): bool => false);
        $this->holdTenantInWizard();

        $this->assertNull($this->app->make(OnboardingChecklist::class)->for(true, null));
    }

    public function test_a_tenant_that_never_went_through_the_wizard_has_no_checklist(): void
    {
        $this->registerStep(OnboardingOrigin::Package, 'extra', false, static fn (): bool => false);

        $this->actingAs($this->admin())->get($this->panelUrl('/admin'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('onboardingChecklist', null)->etc());
    }

    public function test_a_populated_tenant_that_provisioning_had_started_gets_no_checklist(): void
    {
        $this->holdTenantInWizard();
        Assistant::factory()->create();

        $this->app->make(OnboardingWizard::class)->close(populated: true);

        $settings = new OnboardingSettings();
        $this->assertSame(OnboardingStatus::Complete, $settings->state);
        $this->assertNull($settings->started_at);
        $this->assertSame($this->app->make(OnboardingChecklist::class)->registeredKeys(), $settings->known_steps);

        // The languages step is still open, yet nothing is offered.
        $this->actingAs($this->admin())->get($this->panelUrl('/admin'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('onboardingChecklist', null)->etc());
    }

    public function test_an_onboarded_tenant_with_an_open_step_gets_the_checklist_in_the_registry_order(): void
    {
        $this->registerStep(OnboardingOrigin::Package, 'extra', false, static fn (): bool => false);
        $this->onboardTenant();

        $this->actingAs($this->admin())->get($this->panelUrl('/admin'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('onboardingChecklist.items', [
                    ['key' => 'languages', 'required' => true, 'complete' => true, 'url' => '/admin/onboarding/languages'],
                    ['key' => 'first_assistant', 'required' => true, 'complete' => true, 'url' => '/admin/onboarding/assistant'],
                    ['key' => 'channel', 'required' => false, 'complete' => false, 'url' => '/admin/onboarding/channel'],
                    ['key' => 'team', 'required' => false, 'complete' => false, 'url' => '/admin/onboarding/team'],
                    ['key' => 'extra', 'required' => false, 'complete' => false, 'url' => '/admin/usage'],
                ])
                ->where('onboardingChecklist.remaining', 3)
                ->where('onboardingChecklist.hideUrl', '/admin/onboarding/checklist/hide')
                ->etc());
    }

    public function test_it_is_not_shown_to_staff_who_may_not_manage_settings_or_when_everything_is_done(): void
    {
        $done = false;
        $this->registerStep(OnboardingOrigin::Package, 'extra', false, static function () use (&$done): bool {
            return $done;
        });
        $this->onboardTenant();
        $this->completeCoreOptionalSteps();

        $this->actingAs($this->manager())->get($this->panelUrl('/admin'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('onboardingChecklist', null)->etc());

        $done = true;

        $this->actingAs($this->admin())->get($this->panelUrl('/admin'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('onboardingChecklist', null)->etc());
    }

    public function test_the_optional_core_steps_are_open_items_with_their_wizard_pages_and_count_as_remaining(): void
    {
        $this->onboardTenant();

        $this->actingAs($this->admin())->get($this->panelUrl('/admin'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('onboardingChecklist.items.2', ['key' => 'channel', 'required' => false, 'complete' => false, 'url' => '/admin/onboarding/channel'])
                ->where('onboardingChecklist.items.3', ['key' => 'team', 'required' => false, 'complete' => false, 'url' => '/admin/onboarding/team'])
                ->where('onboardingChecklist.remaining', 2)
                ->etc());
    }

    public function test_it_is_not_shown_to_the_platform_support_user(): void
    {
        config(['tenancy.support_access.enabled' => true]);
        $this->registerStep(OnboardingOrigin::Package, 'extra', false, static fn (): bool => false);
        $this->onboardTenant();
        $support = $this->app->make(PlatformSupportUserService::class)->ensure();

        $this->actingAs($support)->withSession([SupportAccessSession::KEY => [
            'entry_id'       => 'entry-1',
            'operator_name'  => 'Olga',
            'operator_email' => 'olga@example.com',
            'expires_at'     => now()->addHour()->toIso8601String(),
        ]])->get($this->panelUrl('/admin'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('onboardingChecklist', null)->etc());
    }

    public function test_hiding_is_per_user_and_a_new_step_brings_the_checklist_back(): void
    {
        $this->registerStep(OnboardingOrigin::Shell, 'welcome', false, static fn (): bool => false);
        $this->onboardTenant();
        $admin = $this->admin();
        $other = $this->admin();

        $this->actingAs($admin)->post($this->panelUrl('/admin/onboarding/checklist/hide'))->assertRedirect();

        $this->assertSame(['welcome', 'languages', 'first_assistant', 'channel', 'team'], $admin->fresh()->onboarding_checklist_hidden);
        $this->assertNull($other->fresh()->onboarding_checklist_hidden);
        $this->actingAs($admin)->get($this->panelUrl('/admin'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('onboardingChecklist', null)->etc());
        $this->actingAs($other)->get($this->panelUrl('/admin'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('onboardingChecklist.items', 5)->etc());

        $this->registerStep(OnboardingOrigin::Package, 'extra', false, static fn (): bool => false);

        $this->actingAs($admin)->get($this->panelUrl('/admin'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('onboardingChecklist.items', 6)->etc());
    }

    public function test_hiding_needs_the_settings_permission(): void
    {
        $this->onboardTenant();
        $manager = $this->manager();

        $this->actingAs($manager)->post($this->panelUrl('/admin/onboarding/checklist/hide'))->assertForbidden();

        $this->assertNull($manager->fresh()->onboarding_checklist_hidden);
        $this->assertFalse($manager->can(Permission::ManageSettings->value));
    }

    public function test_core_links_do_not_dead_end_after_onboarding(): void
    {
        $this->onboardTenant();
        $admin = $this->admin();

        $this->actingAs($admin)->get($this->panelUrl('/admin/onboarding/languages'))
            ->assertRedirect(route('console.admin.tenant-settings.edit'));
        $this->actingAs($admin)->get($this->panelUrl('/admin/onboarding/assistant'))
            ->assertRedirect(route('console.admin.assistants.index'));
        $this->assertFalse($this->app->make(OnboardingState::class)->isPending());
        $this->assertNotNull(Assistant::query()->first());
    }
}
