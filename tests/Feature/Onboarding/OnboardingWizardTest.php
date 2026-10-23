<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Onboarding\Contracts\OnboardingStepInterface;
use App\Domains\Onboarding\Enums\OnboardingOrigin;
use App\Domains\Onboarding\Enums\OnboardingStatus;
use App\Domains\Onboarding\OnboardingBlock;
use App\Domains\Onboarding\Services\OnboardingRegistry;
use App\Domains\Onboarding\Services\OnboardingState;
use App\Domains\Onboarding\Settings\OnboardingSettings;
use App\Domains\Tenancy\Settings\TenantSettings;
use Database\Seeders\TenantAclSeeder;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * Walking the setup wizard: the entry that points at the open step, the two steps of Core, the page for staff who may
 * not manage settings, and a check that fails.
 */
final class OnboardingWizardTest extends FeatureTestCase
{
    use InteractsWithOnboarding;

    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);

        $settings                        = $this->app->make(TenantSettings::class);
        $settings->content_base_language = 'en';
        $settings->available_languages   = ['en'];
        $settings->fallback_language     = 'en';
        $settings->save();

        $this->holdTenantInWizard();
    }

    public function test_the_entry_sends_an_administrator_to_the_first_open_step(): void
    {
        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin/onboarding'))
            ->assertRedirect(route('console.admin.onboarding.languages.edit'));
    }

    public function test_the_entry_of_a_finished_wizard_is_the_dashboard(): void
    {
        $this->app->make(OnboardingState::class)->complete();

        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin/onboarding'))
            ->assertRedirect(route('console.admin.dashboard'));
        $this->actingAs($this->admin())->get($this->panelUrl('/admin/onboarding/languages'))->assertRedirect(route('console.admin.dashboard'));
    }

    public function test_staff_who_may_not_manage_settings_see_the_waiting_page(): void
    {
        $this->actingAs($this->manager())
            ->get($this->panelUrl('/admin/onboarding'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Onboarding/Waiting')
                ->where('onboarding.steps.0.key', 'languages')
                ->where('onboarding.steps.0.current', true)
                ->etc());

        $this->actingAs($this->manager())->get($this->panelUrl('/admin/onboarding/languages'))->assertForbidden();
        $this->actingAs($this->manager())->put($this->panelUrl('/admin/onboarding/languages'), $this->languages())->assertForbidden();
    }

    public function test_the_language_page_carries_the_props_of_its_contract(): void
    {
        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin/onboarding/languages'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Onboarding/Languages')
                ->where('onboarding.steps', [
                    ['key' => 'languages', 'required' => true, 'complete' => false, 'current' => true],
                    ['key' => 'first_assistant', 'required' => true, 'complete' => false, 'current' => false],
                ])
                ->where('languages', ['base' => 'en', 'available' => ['en'], 'fallback' => 'en'])
                ->where('options', fn ($options): bool => collect($options)->contains(['value' => 'uk', 'label' => 'Ukrainian — Українська']))
                ->where('baseLanguageLocked', false)
                ->where('updateUrl', '/admin/onboarding/languages')
                ->etc());
    }

    public function test_saving_the_languages_stores_them_confirms_the_step_and_moves_to_the_assistant(): void
    {
        $this->actingAs($this->admin())
            ->put($this->panelUrl('/admin/onboarding/languages'), $this->languages(['content_base_language' => 'ru', 'available_languages' => ['ru', 'uk', 'ru', ''], 'fallback_language' => 'uk']))
            ->assertRedirect(route('console.admin.onboarding.assistant.create'));

        $settings = $this->freshTenantSettings();
        $this->assertSame('ru', $settings->content_base_language);
        $this->assertSame(['ru', 'uk'], $settings->available_languages);
        $this->assertSame('uk', $settings->fallback_language);
        $this->assertTrue($this->app->make(OnboardingState::class)->isConfirmed('languages'));
        $this->assertTrue($this->app->make(OnboardingState::class)->isPending());
    }

    public function test_a_fallback_outside_the_available_languages_is_refused_and_nothing_is_confirmed(): void
    {
        $this->actingAs($this->admin())
            ->put($this->panelUrl('/admin/onboarding/languages'), $this->languages(['available_languages' => ['en'], 'fallback_language' => 'de']))
            ->assertSessionHasErrors('fallback_language');

        $this->assertFalse($this->app->make(OnboardingState::class)->isConfirmed('languages'));
    }

    public function test_a_locked_base_language_cannot_change_but_the_step_is_confirmed(): void
    {
        $assistant = Assistant::factory()->create();
        FlowDefinition::query()->create($this->flowDefinition($assistant));
        $admin = $this->admin();

        $this->actingAs($admin)->get($this->panelUrl('/admin/onboarding/languages'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('baseLanguageLocked', true)->etc());

        $this->actingAs($admin)
            ->put($this->panelUrl('/admin/onboarding/languages'), $this->languages(['content_base_language' => 'ru', 'available_languages' => ['ru'], 'fallback_language' => 'ru']))
            ->assertSessionHasErrors('content_base_language');
        $this->assertSame('en', $this->freshTenantSettings()->content_base_language);

        $this->actingAs($admin)
            ->put($this->panelUrl('/admin/onboarding/languages'), $this->languages(['content_base_language' => 'en', 'available_languages' => ['en', 'de'], 'fallback_language' => 'en']))
            ->assertRedirect();

        $this->assertSame('en', $this->freshTenantSettings()->content_base_language);
        $this->assertSame(['en', 'de'], $this->freshTenantSettings()->available_languages);
        $this->assertTrue($this->app->make(OnboardingState::class)->isConfirmed('languages'));
    }

    public function test_the_assistant_page_offers_only_the_available_languages_and_defaults_to_the_base(): void
    {
        $settings                      = $this->app->make(TenantSettings::class);
        $settings->available_languages = ['en', 'de'];
        $settings->save();

        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin/onboarding/assistant'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Onboarding/Assistant')
                ->where('languageOptions', [['value' => 'en', 'label' => 'English'], ['value' => 'de', 'label' => 'German — Deutsch']])
                ->where('defaultLanguage', 'en')
                ->where('storeUrl', '/admin/onboarding/assistant')
                ->where('onboarding.steps.1.key', 'first_assistant')
                ->etc());
    }

    public function test_an_assistant_in_a_language_the_tenant_did_not_make_available_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post($this->panelUrl('/admin/onboarding/assistant'), ['name' => 'Sales bot', 'default_language' => 'de'])
            ->assertSessionHasErrors('default_language');

        $this->assertSame(0, Assistant::query()->count());
    }

    public function test_the_last_step_closes_the_wizard_and_opens_the_new_assistant(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put($this->panelUrl('/admin/onboarding/languages'), $this->languages())->assertRedirect(route('console.admin.onboarding.assistant.create'));

        $response = $this->actingAs($admin)->post($this->panelUrl('/admin/onboarding/assistant'), ['name' => 'Sales bot', 'default_language' => 'en']);

        $assistant = Assistant::query()->sole();
        $response->assertRedirect(route('console.home', ['assistant' => (string) $assistant->getKey()]));
        $this->assertSame('Sales bot', $assistant->name);
        $this->assertSame('en', $assistant->default_language);

        $settings = new OnboardingSettings();
        $this->assertSame(OnboardingStatus::Complete, $settings->state);
        $this->assertNotNull($settings->completed_at);
        $this->actingAs($admin)->get($this->panelUrl('/admin'))->assertOk();
    }

    public function test_an_assistant_created_first_leaves_only_the_languages_open(): void
    {
        $assistant = Assistant::factory()->create();
        $admin     = $this->admin();

        $this->actingAs($admin)->get($this->panelUrl('/admin/onboarding/assistant'))->assertRedirect(route('console.admin.onboarding.show'));

        $this->actingAs($admin)->put($this->panelUrl('/admin/onboarding/languages'), $this->languages())
            ->assertRedirect(route('console.entry'));

        $this->assertFalse($this->app->make(OnboardingState::class)->isPending());
        $this->assertNotNull($assistant);
    }

    public function test_a_check_that_throws_is_counted_complete_but_never_closes_the_wizard(): void
    {
        $this->app->make(OnboardingRegistry::class)->register(new OnboardingBlock(OnboardingOrigin::Package, [BrokenStep::class]));
        $admin = $this->admin();

        $this->actingAs($admin)->put($this->panelUrl('/admin/onboarding/languages'), $this->languages())->assertRedirect(route('console.admin.onboarding.assistant.create'));
        $this->actingAs($admin)->post($this->panelUrl('/admin/onboarding/assistant'), ['name' => 'Sales bot', 'default_language' => 'en'])
            ->assertRedirect(route('console.admin.onboarding.show'));

        $this->assertTrue($this->app->make(OnboardingState::class)->isPending());

        $this->actingAs($admin)->get($this->panelUrl('/admin/onboarding'))->assertRedirect(route('console.admin.dashboard'));
        $this->actingAs($admin)->get($this->panelUrl('/admin'))->assertOk();
        $this->actingAs($admin)->get($this->panelUrl('/admin/usage'))->assertOk();
        $this->assertTrue($this->app->make(OnboardingState::class)->isPending(), 'Nothing is written on an uncertain answer.');
    }

    public function test_a_clean_all_done_tenant_is_closed_by_the_redirect_chain(): void
    {
        Assistant::factory()->create();
        $this->app->make(OnboardingState::class)->confirm('languages');
        $admin = $this->admin();

        $this->actingAs($admin)->get($this->panelUrl('/admin'))->assertRedirect(route('console.admin.onboarding.show'));
        $this->actingAs($admin)->get($this->panelUrl('/admin/onboarding'))->assertRedirect(route('console.entry'));

        $this->assertFalse($this->app->make(OnboardingState::class)->isPending());
        $this->assertNotNull((new OnboardingSettings())->completed_at);
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, mixed>
     */
    private function languages(array $overrides = []): array
    {
        return [...['content_base_language' => 'en', 'available_languages' => ['en'], 'fallback_language' => 'en'], ...$overrides];
    }

    /**
     * @return array<string, mixed>
     */
    private function flowDefinition(Assistant $assistant): array
    {
        $flow = FlowDraft::factory()->create(['assistant_id' => $assistant->getKey()]);

        return [
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => $flow->flow_id,
            'version'   => 1,
            'name'      => $flow->name,
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ];
    }
}

/**
 * A step of a package whose check fails.
 */
final class BrokenStep implements OnboardingStepInterface
{
    public function key(): string
    {
        return 'broken';
    }

    public function required(): bool
    {
        return true;
    }

    public function route(): string
    {
        return 'console.admin.onboarding.show';
    }

    public function routes(): array
    {
        return [];
    }

    public function isComplete(): bool
    {
        throw new RuntimeException('the package is down');
    }
}
