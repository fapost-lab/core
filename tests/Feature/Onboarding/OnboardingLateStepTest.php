<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Onboarding\Enums\OnboardingOrigin;
use App\Domains\Onboarding\Services\OnboardingAnnouncements;
use App\Domains\Onboarding\Services\OnboardingLateSteps;
use App\Domains\Onboarding\Services\OnboardingState;
use App\Domains\Onboarding\Settings\OnboardingAnnouncementSettings;
use App\Domains\Onboarding\Settings\OnboardingSettings;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\PlatformSupportUserService;
use App\Domains\Staff\Support\SupportAccessSession;
use App\Infrastructure\Onboarding\LateOnboardingStepNotification;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * A required step that appears after the tenant finished onboarding: the banner for everyone, the bell notification
 * once for the people who can finish it, and what is not a late step.
 */
final class OnboardingLateStepTest extends FeatureTestCase
{
    use InteractsWithOnboarding;
    use InteractsWithOnboardingSteps;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_a_closing_wizard_records_every_registered_step_as_known(): void
    {
        $this->registerStep(OnboardingOrigin::Package, 'extra', false, static fn (): bool => true);
        $this->onboardTenant();

        $this->assertSame(['languages', 'first_assistant', 'channel', 'team', 'extra'], $this->app->make(OnboardingState::class)->knownSteps());
    }

    public function test_starting_the_wizard_again_forgets_the_known_and_announced_steps(): void
    {
        $state         = $this->app->make(OnboardingState::class);
        $announcements = $this->app->make(OnboardingAnnouncements::class);
        $announcements->announce(['x']);

        $state->start();

        $this->assertSame([], $state->knownSteps());
        $this->assertSame([], $announcements->announced());
    }

    public function test_announcing_never_writes_the_wizards_state(): void
    {
        $this->onboardTenant();
        $this->app->make(OnboardingState::class)->start();
        $before = DB::table('settings')->where('group', 'onboarding')->orderBy('name')->get()->all();

        $this->app->make(OnboardingAnnouncements::class)->announce(['late']);

        $this->assertEquals($before, DB::table('settings')->where('group', 'onboarding')->orderBy('name')->get()->all());
        $this->assertTrue($this->app->make(OnboardingState::class)->isPending());
        $this->assertSame(['late'], $this->app->make(OnboardingAnnouncements::class)->announced());
    }

    public function test_the_settings_migration_leaves_an_existing_tenant_knowing_the_steps_of_core(): void
    {
        $settings = new OnboardingSettings();

        $this->assertSame(['languages', 'first_assistant'], $settings->known_steps);
        $this->assertSame([], (new OnboardingAnnouncementSettings())->announced_steps);
    }

    public function test_an_existing_tenant_whose_languages_were_never_confirmed_gets_no_banner(): void
    {
        $this->actingAs($this->admin())->get($this->panelUrl('/admin'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('onboardingLateSteps', null)->etc());
        $this->assertSame(0, User::query()->first()->notifications()->count());
    }

    public function test_a_new_required_step_shows_a_banner_and_notifies_the_holders_of_the_permission_once(): void
    {
        $this->onboardTenant();
        $this->registerStep(OnboardingOrigin::Package, 'late', true, static fn (): bool => false);

        $role = Role::query()->create(['name' => 'settings_keeper', 'guard_name' => 'web', 'priority' => 10, 'is_system' => false]);
        $role->givePermissionTo(Permission::ManageSettings->value);
        $keeper = User::factory()->create();
        $keeper->assignRole($role);
        $admin   = $this->admin();
        $analyst = $this->manager();

        $this->actingAs($admin)->get($this->panelUrl('/admin'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('onboardingLateSteps', ['steps' => [['key' => 'late', 'url' => '/admin/usage']], 'canAct' => true])
                ->etc());

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(1, $keeper->notifications()->count());
        $this->assertSame(0, $analyst->notifications()->count());
        $this->assertSame(LateOnboardingStepNotification::class, $admin->notifications()->first()->type);
        $this->assertSame(['late'], $this->app->make(OnboardingAnnouncements::class)->announced());

        $data = $admin->notifications()->first()->data;
        $this->assertSame('/admin/usage', $data['action']['url']);
        $this->assertStringContainsString('late', $data['body']);

        $this->actingAs($admin)->get($this->panelUrl('/admin'))->assertOk();
        $this->actingAs($analyst)->get($this->panelUrl('/admin'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('onboardingLateSteps.canAct', false)->has('onboardingLateSteps.steps', 1)->etc());

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(1, $keeper->notifications()->count());
        $this->assertSame(0, $analyst->notifications()->count());

        $latecomer = $this->admin();
        $this->actingAs($latecomer)->get($this->panelUrl('/admin'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('onboardingLateSteps.steps', 1)->etc());
        $this->assertSame(0, $latecomer->notifications()->count(), 'A later administrator sees the banner, not a notification.');
    }

    public function test_a_tenant_the_settings_migrations_did_not_reach_gets_no_banner_and_no_notification(): void
    {
        DB::table('settings')->whereIn('group', ['onboarding', 'onboarding_announcements'])->delete();
        $admin = $this->admin();

        $this->actingAs($admin)->get($this->panelUrl('/admin'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('onboardingLateSteps', null)->etc());
        $this->actingAs($admin)->get($this->panelUrl('/admin'))->assertOk();

        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_a_late_step_whose_announcement_cannot_be_recorded_notifies_nobody(): void
    {
        $this->onboardTenant();
        $this->registerStep(OnboardingOrigin::Package, 'late', true, static fn (): bool => false);
        DB::table('settings')->where('group', 'onboarding_announcements')->delete();
        $admin = $this->admin();

        $this->actingAs($admin)->get($this->panelUrl('/admin'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('onboardingLateSteps.steps', 1)->etc());
        $this->actingAs($admin)->get($this->panelUrl('/admin'))->assertOk();

        $this->assertSame(0, $admin->notifications()->count(), 'A record that cannot be written must not turn into a notification on every load.');
    }

    public function test_a_tenant_is_told_about_late_steps_at_most_once_a_day(): void
    {
        $this->onboardTenant();
        $this->registerStep(OnboardingOrigin::Package, 'late', true, static fn (): bool => false);
        $admin         = $this->admin();
        $announcements = $this->app->make(OnboardingAnnouncements::class);

        $this->actingAs($admin)->get($this->panelUrl('/admin'))->assertOk();
        $this->assertSame(1, $admin->notifications()->count());

        // The record is lost (a restarted wizard forgets it): the same day still brings no second notification.
        $announcements->forget();
        $this->actingAs($admin)->get($this->panelUrl('/admin'))->assertOk();
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame([], $announcements->announced());

        $this->travel(25)->hours();
        $this->actingAs($admin)->get($this->panelUrl('/admin'))->assertOk();
        $this->actingAs($admin)->get($this->panelUrl('/admin'))->assertOk();

        $this->assertSame(2, $admin->notifications()->count());
        $this->assertSame(['late'], $announcements->announced());
    }

    public function test_the_bell_shows_a_late_step_in_the_language_of_the_page(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['locale' => null])->save();
        Notification::sendNow($admin, new LateOnboardingStepNotification('languages', '/admin/onboarding/languages'));
        $this->assertStringContainsString('Content languages', $admin->notifications()->first()->data['body']);

        $item = $this->actingAs($admin)->getJson($this->panelUrl('/console/notifications?lang=ru'))->assertOk()->json('items.0');

        $this->assertSame(trans('console.onboarding.late.notification_title', [], 'ru'), $item['title']);
        $this->assertSame(
            trans('console.onboarding.late.notification_body', ['step' => trans('console.onboarding.steps.languages.title', [], 'ru')], 'ru'),
            $item['body'],
        );
        $this->assertSame(['label' => trans('console.onboarding.late.action', [], 'ru'), 'url' => '/admin/onboarding/languages'], $item['action']);
    }

    public function test_the_platform_support_user_sees_the_banner_but_is_not_notified(): void
    {
        config(['tenancy.support_access.enabled' => true]);
        $this->onboardTenant();
        $this->registerStep(OnboardingOrigin::Package, 'late', true, static fn (): bool => false);
        $support = $this->app->make(PlatformSupportUserService::class)->ensure();

        $this->actingAs($support)->withSession([SupportAccessSession::KEY => [
            'entry_id'       => 'entry-1',
            'operator_name'  => 'Olga',
            'operator_email' => 'olga@example.com',
            'expires_at'     => now()->addHour()->toIso8601String(),
        ]])->get($this->panelUrl('/admin'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('onboardingLateSteps.steps', 1)->etc());

        $this->assertSame(0, $support->notifications()->count());
        $this->assertSame([], $this->app->make(OnboardingAnnouncements::class)->announced());
    }

    public function test_a_known_step_that_became_open_again_is_not_late(): void
    {
        $this->onboardTenant();
        Assistant::query()->delete();
        $admin = $this->admin();

        $this->actingAs($admin)->get($this->panelUrl('/admin'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('onboardingLateSteps', null)
                // The reopened required step plus Core's two optional ones.
                ->where('onboardingChecklist.remaining', 3)
                ->etc());

        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_a_late_optional_step_is_not_announced(): void
    {
        $this->onboardTenant();
        $this->registerStep(OnboardingOrigin::Package, 'late', false, static fn (): bool => false);

        $this->actingAs($this->admin())->get($this->panelUrl('/admin'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('onboardingLateSteps', null)->etc());
    }

    public function test_a_tenant_still_in_the_wizard_has_no_late_step(): void
    {
        $this->registerStep(OnboardingOrigin::Package, 'late', true, static fn (): bool => false);
        $this->holdTenantInWizard();

        $this->assertSame([], $this->app->make(OnboardingLateSteps::class)->open());
    }

    public function test_a_late_check_that_throws_shows_no_banner_and_is_logged(): void
    {
        $this->onboardTenant();
        $this->registerStep(OnboardingOrigin::Package, 'late', true, static function (): bool {
            throw new RuntimeException('boom');
        });
        $logger = Mockery::spy(LoggerInterface::class);
        $this->app->instance(LoggerInterface::class, $logger);
        $admin = $this->admin();

        $this->actingAs($admin)->get($this->panelUrl('/admin'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('onboardingLateSteps', null)->etc());

        $logger->shouldHaveReceived('warning')->with(Mockery::pattern('/late onboarding step could not be checked/'), Mockery::type('array'))->once();
        $this->assertSame(0, $admin->notifications()->count());
    }
}
