<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Enums\ActivationAction;
use App\Domains\Solution\Enums\ActivationActorKind;
use App\Domains\Solution\Enums\SolutionActivationStatus;
use App\Domains\Solution\Infrastructure\SolutionActivationEventRecord;
use App\Domains\Solution\Infrastructure\SolutionActivationRecord;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Solution\Contracts\SolutionEntitlementInterface;
use Fapost\Foundation\Solution\DTO\SolutionEntitlement;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeSolutionEntitlement;
use Tests\Support\FakeVendor;
use Tests\Support\InstallsShowcaseSolution;

/**
 * The Solutions screen of the admin panel: what is installed, what the tenant has on, what each Solution registers, the
 * reason a console UI may not load, and turning a Solution on or off with the staff user in the journal.
 */
final class AdminSolutionsConsoleTest extends FeatureTestCase
{
    use InstallsShowcaseSolution;

    /** @var list<string> */
    private array $copies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    protected function tearDown(): void
    {
        $this->removeShowcase();

        foreach ($this->copies as $copy) {
            (new Filesystem())->deleteDirectory($copy);
        }

        parent::tearDown();
    }

    public function test_it_lists_every_installed_solution_with_its_identity_state_and_what_it_registers(): void
    {
        $this->installShowcase(alsoInstalled: [$this->feedback()]);
        $this->activateShowcase();

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/AdminSolutions/Index')
                ->where('navigation.mode', 'admin')
                ->has('solutions', 2)
                ->where('solutions.0.id', 'feedback')
                ->where('solutions.0.name', 'Feedback')
                ->where('solutions.0.active', false)
                ->where('solutions.0.actions', ['feedback.run'])
                ->where('solutions.0.ui', null)
                ->where('solutions.0.lastChange', null)
                ->where('solutions.0.canActivate', true)
                ->where('solutions.0.entitlement', ['state' => 'entitled', 'paused' => false, 'action' => null])
                ->where('solutions.0.urls.activate', '/admin/solutions/feedback/activate')
                ->where('solutions.1.id', 'showcase')
                ->where('solutions.1.active', true)
                ->where('solutions.1.ui.state', 'published')
                ->where('solutions.1.ui.mismatches', [])
                ->where('solutions.1.ui.application', fn ($label): bool => is_string($label) && '' !== $label)
                ->where('solutions.1.ui.registrations', function ($registrations): bool {
                    $points = collect($registrations)->pluck('point')->unique()->sort()->values()->all();

                    $this->assertSame(['context_action', 'dashboard_widget', 'settings_tab', 'shell_element', 'table_column'], $points);

                    return true;
                })
                ->where('solutions.1.lastChange.action', 'activated')
                ->where('solutions.1.lastChange.actor', 'console')
                ->where('missing', [])
                ->etc());
    }

    public function test_an_admin_turns_a_solution_on_and_the_journal_names_them(): void
    {
        $this->installShowcase();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post($this->url('/showcase/activate'))
            ->assertRedirect($this->url())
            ->assertSessionHasNoErrors();

        $this->forgetActive();
        $this->assertTrue($this->isActive('showcase'));

        $event = SolutionActivationEventRecord::query()->where('solution_id', 'showcase')->firstOrFail();
        $this->assertSame(ActivationAction::Activated, $event->action);
        $this->assertSame(ActivationActorKind::User, $event->actor_kind);
        $this->assertSame((string) $admin->getKey(), $event->actor_id);
        $this->assertSame($admin->name, $event->actor_label);

        $this->actingAs($admin)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('solutions.0.active', true)
                ->where('solutions.0.lastChange.actor', $admin->name)
                ->etc());
    }

    public function test_turning_it_on_twice_changes_nothing_and_journals_once(): void
    {
        $this->installShowcase();
        $admin = $this->admin();

        $this->actingAs($admin)->post($this->url('/showcase/activate'))->assertRedirect();
        $this->actingAs($admin)->post($this->url('/showcase/activate'))->assertRedirect();

        $this->assertSame(1, SolutionActivationEventRecord::query()->count());
    }

    public function test_an_admin_turns_a_solution_off_and_it_keeps_its_row_and_journals_the_user(): void
    {
        $this->installShowcase();
        $this->activateShowcase();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post($this->url('/showcase/deactivate'))
            ->assertRedirect($this->url());

        $this->forgetActive();
        $this->assertFalse($this->isActive('showcase'));

        // Deactivation updates the row; it never deletes it or anything the Solution owns.
        $this->assertSame(SolutionActivationStatus::Inactive, SolutionActivationRecord::query()->where('solution_id', 'showcase')->firstOrFail()->status);

        $last = SolutionActivationEventRecord::query()->where('solution_id', 'showcase')->orderByDesc('occurred_at')->orderByDesc('id')->firstOrFail();
        $this->assertSame(ActivationAction::Deactivated, $last->action);
        $this->assertSame((string) $admin->getKey(), $last->actor_id);
        $this->assertSame(2, SolutionActivationEventRecord::query()->where('solution_id', 'showcase')->count());
    }

    public function test_a_user_without_the_settings_permission_neither_sees_nor_changes_anything(): void
    {
        $this->installShowcase();
        $manager = User::factory()->create();
        $manager->assignRole(RoleEnum::ContentManager->value);

        $this->actingAs($manager)->get($this->url())->assertForbidden();
        $this->actingAs($manager)->post($this->url('/showcase/activate'))->assertForbidden();

        $this->activateShowcase();
        $this->actingAs($manager)->post($this->url('/showcase/deactivate'))->assertForbidden();

        $this->forgetActive();
        $this->assertTrue($this->isActive('showcase'));
        $this->assertSame(1, SolutionActivationEventRecord::query()->count());
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->installShowcase();

        $this->get($this->url())->assertRedirect();
        $this->post($this->url('/showcase/activate'))->assertRedirect();
        $this->assertSame(0, SolutionActivationEventRecord::query()->count());
    }

    public function test_a_solution_that_is_not_installed_cannot_be_turned_on_and_an_unknown_one_is_not_found(): void
    {
        $this->installShowcase();
        $admin = $this->admin();

        $this->actingAs($admin)->post($this->url('/ghost/activate'))->assertNotFound();
        $this->actingAs($admin)->post($this->url('/ghost/deactivate'))->assertNotFound();
        // Not an id a manifest can hold, so it never reaches the controller.
        $this->actingAs($admin)->post($this->url('/Not-An-Id/activate'))->assertNotFound();

        $this->assertSame(0, SolutionActivationRecord::query()->count());
    }

    public function test_a_stored_activation_whose_package_is_gone_is_listed_apart_and_can_be_put_out(): void
    {
        $this->installShowcase();
        $this->inTenant(function (): void {
            $this->app->make(SolutionActivationStoreInterface::class)->change('ghost', SolutionActivationStatus::Active, ActivationActor::console(), null);
        });
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('missing', 1)
                ->where('missing.0.id', 'ghost')
                ->where('missing.0.urls.deactivate', '/admin/solutions/ghost/deactivate')
                ->etc());

        $this->actingAs($admin)->post($this->url('/ghost/deactivate'))->assertRedirect();

        $this->actingAs($admin)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('missing', [])->etc());
    }

    public function test_a_bundle_built_for_another_kit_is_reported_and_its_activation_is_refused_with_the_reason(): void
    {
        $package = $this->copyPackage(static fn (array $ui): array => ['kit' => '^2.0', 'vue' => '^4.0'] + $ui);
        $this->installShowcase(package: $package);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('solutions.0.ui.mismatches', [
                    ['component' => 'kit', 'required' => '^2.0', 'provided' => config('solutions.console_runtime.kit'), 'unreadable' => false],
                    ['component' => 'vue', 'required' => '^4.0', 'provided' => config('solutions.console_runtime.vue'), 'unreadable' => false],
                ])
                ->where('solutions.0.canActivate', false)
                ->etc());

        // The server repeats the check: a request that skips the disabled button is refused too, with the reason.
        $this->actingAs($admin)
            ->from($this->url())
            ->post($this->url('/showcase/activate'))
            ->assertRedirect($this->url())
            ->assertInertiaFlash('error', sprintf('Solution showcase was not turned on. Interface kit: built for ^2.0, this platform ships %s. Vue: built for ^4.0, this platform ships %s.', config('solutions.console_runtime.kit'), config('solutions.console_runtime.vue')));

        $this->forgetActive();
        $this->assertFalse($this->isActive('showcase'));
        $this->assertSame(0, SolutionActivationEventRecord::query()->count());
    }

    public function test_a_vue_range_is_checked_by_major_only(): void
    {
        $package = $this->copyPackage(static fn (array $ui): array => ['vue' => '^3.99', 'inertia' => '>=3.9,<4'] + $ui);
        $this->installShowcase(package: $package);

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('solutions.0.ui.mismatches', [])->where('solutions.0.canActivate', true)->etc());
    }

    public function test_an_active_solution_whose_kit_no_longer_fits_can_still_be_turned_off(): void
    {
        $this->installShowcase();
        $this->activateShowcase();
        $package = $this->copyPackage(static fn (array $ui): array => ['kit' => '^2.0'] + $ui);
        $this->installShowcase(package: $package);

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('solutions.0.active', true)->where('solutions.0.canActivate', false)->etc());

        $this->actingAs($this->admin())->post($this->url('/showcase/deactivate'))->assertRedirect();

        $this->forgetActive();
        $this->assertFalse($this->isActive('showcase'));
    }

    public function test_a_range_core_cannot_read_is_reported_as_unreadable_not_as_a_fit(): void
    {
        $package = $this->copyPackage(static fn (array $ui): array => ['inertia' => 'latest'] + $ui);
        $this->installShowcase(package: $package);

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('solutions.0.ui.mismatches.0.component', 'inertia')
                ->where('solutions.0.ui.mismatches.0.unreadable', true)
                ->etc());
    }

    public function test_a_console_ui_that_is_not_published_yet_says_so(): void
    {
        $this->installShowcase(publish: false);

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('solutions.0.ui.state', 'not_published')
                ->where('solutions.0.ui.pages', [])
                ->where('solutions.0.ui.mismatches', [])
                ->etc());
    }

    public function test_a_solution_the_plan_does_not_include_cannot_be_turned_on_and_shows_the_operator_button(): void
    {
        $this->installShowcase();
        $this->operator()->default = SolutionEntitlement::only([], 'See plans', 'https://ops.example.test/plans');
        $admin                     = $this->admin();

        $this->actingAs($admin)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('solutions.0.canActivate', false)
                ->where('solutions.0.entitlement', ['state' => 'not_entitled', 'paused' => false, 'action' => ['label' => 'See plans', 'url' => 'https://ops.example.test/plans']])
                ->etc());

        $this->actingAs($admin)
            ->from($this->url())
            ->post($this->url('/showcase/activate'))
            ->assertRedirect($this->url())
            ->assertInertiaFlash('error', 'Solution showcase was not turned on. This Solution is not included in your plan.');

        $this->assertSame(0, SolutionActivationRecord::query()->count());
        $this->assertSame(0, SolutionActivationEventRecord::query()->count());
    }

    public function test_a_solution_that_left_the_plan_is_paused_with_its_row_kept_and_can_be_turned_off(): void
    {
        $this->installShowcase();
        $this->activateShowcase();
        $this->operator()->default = SolutionEntitlement::only([]);
        $admin                     = $this->admin();

        $this->actingAs($admin)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('solutions.0.active', false)
                ->where('solutions.0.entitlement.state', 'not_entitled')
                ->where('solutions.0.entitlement.paused', true)
                ->etc());

        $this->assertSame(SolutionActivationStatus::Active, SolutionActivationRecord::query()->where('solution_id', 'showcase')->firstOrFail()->status);
        $this->assertSame(1, SolutionActivationEventRecord::query()->count(), 'Losing the entitlement journals nothing.');

        $this->actingAs($admin)->post($this->url('/showcase/deactivate'))->assertRedirect();
        $this->assertSame(SolutionActivationStatus::Inactive, SolutionActivationRecord::query()->where('solution_id', 'showcase')->firstOrFail()->status);

        // Back in the plan, a Solution the tenant turned off stays off.
        $this->operator()->default = null;
        $this->app->forgetScopedInstances();
        $this->assertFalse($this->isActive('showcase'));
    }

    public function test_while_the_plan_cannot_be_checked_nothing_can_be_turned_on_and_what_is_on_stays_on(): void
    {
        Exceptions::fake();
        $this->installShowcase(alsoInstalled: [$this->feedback()]);
        $this->activateShowcase();
        $this->operator()->failing = true;
        $admin                     = $this->admin();

        $this->actingAs($admin)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('solutions.0.id', 'feedback')
                ->where('solutions.0.canActivate', false)
                ->where('solutions.0.entitlement.state', 'unavailable')
                ->where('solutions.1.active', true)
                ->etc());

        $this->actingAs($admin)
            ->from($this->url())
            ->post($this->url('/feedback/activate'))
            ->assertInertiaFlash('error', 'Solution feedback was not turned on. Your plan could not be checked right now. Try again later.');

        $this->assertNull(SolutionActivationRecord::query()->where('solution_id', 'feedback')->first());
    }

    public function test_the_page_has_its_menu_entry_for_those_who_may_open_it(): void
    {
        $this->installShowcase();

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('navigation.groups', fn ($groups): bool => collect($groups)
                    ->flatMap(static fn (array $group): array => $group['items'])
                    ->contains(static fn (array $item): bool => 'console.admin.solutions.index' === $item['key'] && '/admin/solutions' === $item['href']))
                ->etc());
    }

    public function test_the_page_is_translated_into_each_language(): void
    {
        $this->installShowcase();
        $admin = $this->admin();

        foreach (['en' => 'Solutions', 'ru' => 'Решения', 'uk' => 'Рішення'] as $locale => $title) {
            $this->actingAs($admin)
                ->get($this->url('?lang=' . $locale))
                ->assertInertia(fn (AssertableInertia $page) => $page->where('translations.console.solutions.title', $title)->etc());
        }
    }

    private function url(string $suffix = ''): string
    {
        return $this->panelUrl('/admin/solutions' . $suffix);
    }

    private function operator(): FakeSolutionEntitlement
    {
        $operator = new FakeSolutionEntitlement();
        $this->app->instance(SolutionEntitlementInterface::class, $operator);
        $this->app->forgetScopedInstances();

        return $operator;
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function feedback(): array
    {
        return FakeVendor::solutionComposer(['id' => 'feedback', 'name' => 'Feedback', 'actions' => ['feedback.run']], ['name' => 'acme/fapost-feedback']);
    }

    private function isActive(string $solution): bool
    {
        return (bool) $this->inTenant(fn (): bool => $this->app->make(ActiveSolutions::class)->isActive($solution));
    }

    private function forgetActive(): void
    {
        $this->app->forgetInstance(ActiveSolutions::class);
    }

    private function inTenant(callable $callback): mixed
    {
        return $this->app->make(TenantSwitcher::class)->runForTenant(new RuntimeTenant('tenant-one', 'main', 'one'), $callback(...));
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function copyPackage(callable $change): string
    {
        $copy = sys_get_temp_dir() . '/fapost-showcase-' . bin2hex(random_bytes(6));
        (new Filesystem())->copyDirectory(self::showcasePackage(), $copy);
        $this->copies[] = $copy;

        $file = $copy . '/dist/fapost-ui.json';
        $ui   = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        file_put_contents($file, json_encode($change($ui), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $copy;
    }
}
