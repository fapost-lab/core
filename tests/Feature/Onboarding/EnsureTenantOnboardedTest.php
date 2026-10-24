<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Onboarding\Contracts\OnboardingStepInterface;
use App\Domains\Onboarding\Enums\OnboardingOrigin;
use App\Domains\Onboarding\OnboardingBlock;
use App\Domains\Onboarding\Services\OnboardingRegistry;
use App\Domains\Staff\Services\PlatformSupportUserService;
use App\Domains\Staff\Support\SupportAccessSession;
use App\Http\Middleware\HandleInertiaRequests;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeTenantAccessMode;

/**
 * The gate that holds a tenant's console in the setup wizard: what it redirects, what it lets through, and that an
 * onboarded tenant pays for one read of the state and nothing else.
 */
final class EnsureTenantOnboardedTest extends FeatureTestCase
{
    use InteractsWithOnboarding;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_a_tenant_that_was_never_put_in_the_wizard_is_not_held(): void
    {
        $this->actingAs($this->admin())->get($this->panelUrl('/admin'))->assertOk();
    }

    public function test_every_page_of_the_console_builder_and_media_redirects_to_the_wizard(): void
    {
        $this->holdTenantInWizard();
        $admin     = $this->admin();
        $assistant = Assistant::factory()->create();
        $draft     = FlowDraft::factory()->create(['tenant_id' => $assistant->tenant_id, 'assistant_id' => $assistant->getKey()]);
        // The path, not the absolute URL: the builder and media URLs carry no panel host, and CI's APP_URL has a port.
        $wizard = (string)parse_url(route('console.admin.onboarding.show'), PHP_URL_PATH);

        foreach ([
            $this->panelUrl('/admin'),
            $this->panelUrl('/admin/tenant-settings'),
            $this->panelUrl("/assistant/{$assistant->getKey()}/dashboard"),
            "/builder/flows/{$draft->flow_id}",
            '/media/folders',
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertRedirectContains($wizard);
        }
    }

    public function test_an_inertia_visit_gets_the_location_response(): void
    {
        $this->holdTenantInWizard();

        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin'), [
                'X-Inertia'        => 'true',
                'X-Requested-With' => 'XMLHttpRequest',
                // The current asset version, so Inertia's own version check does not answer the 409 first.
                'X-Inertia-Version' => (string)app(HandleInertiaRequests::class)->version(Request::create('/')),
            ])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('console.admin.onboarding.show'));
    }

    public function test_writes_and_json_calls_pass(): void
    {
        $this->holdTenantInWizard();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/media/folders', ['name' => 'Projects'])->assertCreated();
        $this->actingAs($admin)->getJson('/media/folders')->assertOk();
        $this->actingAs($admin)->getJson($this->panelUrl('/console/notifications/count'))->assertOk();
    }

    public function test_the_wizard_the_sign_out_and_the_step_routes_pass(): void
    {
        $this->holdTenantInWizard();
        $admin = $this->admin();

        $this->actingAs($admin)->get($this->panelUrl('/admin/onboarding/languages'))->assertOk();
        $this->actingAs($admin)->get($this->panelUrl('/admin/onboarding/assistant'))->assertOk();
        $this->actingAs($admin)->get($this->panelUrl('/admin/onboarding'))->assertRedirect(route('console.admin.onboarding.languages.edit'));
    }

    public function test_a_route_a_step_lists_passes_even_outside_the_wizards_own_prefix(): void
    {
        $this->holdTenantInWizard();
        $this->registerExtraStep(routes: ['console.admin.usage.index']);
        $admin = $this->admin();

        $this->actingAs($admin)->get($this->panelUrl('/admin/usage'))->assertOk();
        $this->actingAs($admin)->get($this->panelUrl('/admin/health'))->assertRedirect(route('console.admin.onboarding.show'));
    }

    public function test_platform_support_passes(): void
    {
        config(['tenancy.support_access.enabled' => true]);
        $this->holdTenantInWizard();
        $support = $this->app->make(PlatformSupportUserService::class)->ensure();

        $this->actingAs($support)->withSession([SupportAccessSession::KEY => [
            'entry_id'       => 'entry-1',
            'operator_name'  => 'Olga',
            'operator_email' => 'olga@example.com',
            'expires_at'     => now()->addHour()->toIso8601String(),
        ]])->get($this->panelUrl('/admin'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('onboarding.pending', true)->etc());
    }

    public function test_a_stopped_tenant_passes(): void
    {
        $this->holdTenantInWizard();
        $this->app->instance(TenantAccessModeInterface::class, FakeTenantAccessMode::stopped());

        $this->actingAs($this->admin())->get($this->panelUrl('/admin'))->assertOk();
    }

    public function test_an_onboarded_tenant_does_not_ask_the_steps_anything(): void
    {
        $step  = $this->registerExtraStep(routes: []);
        $admin = $this->admin();

        $this->actingAs($admin)->get($this->panelUrl('/admin'))->assertOk();
        $this->actingAs($admin)->get($this->panelUrl('/admin/usage'))->assertOk();

        // The shell builds the steps to read their keys (the late-step banner); nothing asks one whether it is done.
        $this->assertSame(0, $step->checks);
    }

    public function test_a_tenant_whose_state_cannot_be_read_is_not_held(): void
    {
        DB::table('settings')->where('group', 'onboarding')->delete();

        $this->actingAs($this->admin())->get($this->panelUrl('/admin'))->assertOk();
    }

    /**
     * A third-party step (a package's block) that counts how often it was built and asked.
     *
     * @param  list<string>  $routes
     */
    private function registerExtraStep(array $routes): object
    {
        $step = new class () implements OnboardingStepInterface {
            public int $built = 0;

            public int $checks = 0;

            /** @var list<string> */
            public array $routes = [];

            public function key(): string
            {
                return 'extra';
            }

            public function required(): bool
            {
                return false;
            }

            public function route(): string
            {
                return 'console.admin.onboarding.show';
            }

            public function routes(): array
            {
                return $this->routes;
            }

            public function isComplete(): bool
            {
                $this->checks++;

                return false;
            }
        };
        $step->routes = $routes;

        $this->app->bind($step::class, function () use ($step): OnboardingStepInterface {
            $step->built++;

            return $step;
        });
        $this->app->make(OnboardingRegistry::class)->register(new OnboardingBlock(OnboardingOrigin::Package, [$step::class]));

        return $step;
    }
}
