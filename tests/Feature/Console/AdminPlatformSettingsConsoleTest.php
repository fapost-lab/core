<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Http\Middleware\EndExpiredSupportSession;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Services\PlatformSettings;
use Database\Seeders\TenantAclSeeder;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;

/**
 * The platform settings screen of a single-tenant deployment: the settings with their defaults, the save that stores or
 * resets them through the domain, and the access rules (administrators only, never the support user).
 */
final class AdminPlatformSettingsConsoleTest extends FeatureTestCase
{
    private const string KEY = 'flow.execution.max_iterations';

    private const string FIELD = 'flow_execution_max_iterations';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_the_page_lists_the_settings_shown_in_single_mode_with_their_values(): void
    {
        app(PlatformSettings::class)->update([self::KEY => 250], 'cli:test');
        $this->app->forgetScopedInstances();

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/PlatformSettings/Edit')
                ->where('navigation.mode', 'admin')
                ->where('urls.submit', '/admin/platform-settings')
                ->where('groups', function ($groups): bool {
                    $setting = collect($groups)->flatMap(static fn (array $group): array => $group['settings'])->firstWhere('key', self::KEY);

                    return null !== $setting
                        && self::FIELD === $setting['field']
                        && 'integer' === $setting['type']
                        && 250 === $setting['value']
                        && true === $setting['stored']
                        && 10 === $setting['min']
                        && 10000 === $setting['max']
                        && is_int($setting['default']);
                })
                ->where('groups', fn ($groups): bool => ! collect($groups)
                    ->flatMap(static fn (array $group): array => $group['settings'])
                    ->contains('key', 'tenancy.rename.redirect_days'))
                ->where('navigation.groups', fn ($groups): bool => collect($groups)
                    ->flatMap(static fn (array $group): array => $group['items'])
                    ->contains('key', 'console.admin.platform-settings.edit'))
                ->etc());
    }

    public function test_an_unset_setting_shows_no_stored_value(): void
    {
        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('groups', function ($groups): bool {
                $setting = collect($groups)->flatMap(static fn (array $group): array => $group['settings'])->firstWhere('key', self::KEY);

                return false === $setting['stored'] && null === $setting['value'];
            })->etc());
    }

    public function test_saving_stores_the_value(): void
    {
        $this->actingAs($this->admin())
            ->put($this->url(), [self::FIELD => '300'])
            ->assertRedirect($this->url())
            ->assertInertiaFlash('success', trans('console.platform_settings.saved'));

        $this->assertSame(300, $this->freshSettings()->integer(self::KEY));
    }

    public function test_a_refused_value_is_a_validation_error_and_stores_nothing(): void
    {
        $this->actingAs($this->admin())
            ->put($this->url(), [self::FIELD => '5'])
            ->assertSessionHasErrors([self::FIELD]);

        $this->assertFalse($this->storedState(self::KEY));

        $this->put($this->url(), [self::FIELD => 'many'])->assertSessionHasErrors([self::FIELD]);
        $this->assertFalse($this->storedState(self::KEY));
    }

    public function test_an_empty_field_resets_the_setting_to_its_default(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put($this->url(), [self::FIELD => '300'])->assertSessionHasNoErrors();
        $this->assertTrue($this->storedState(self::KEY));

        $this->put($this->url(), [self::FIELD => null])->assertSessionHasNoErrors();

        $this->assertFalse($this->storedState(self::KEY));
    }

    public function test_a_field_left_out_is_not_changed(): void
    {
        $this->actingAs($this->admin())->put($this->url(), [self::FIELD => '300'])->assertSessionHasNoErrors();

        $this->put($this->url(), [])->assertSessionHasNoErrors();

        $this->assertSame(300, $this->freshSettings()->integer(self::KEY));
    }

    public function test_the_host_mode_only_setting_cannot_be_saved_here(): void
    {
        $this->actingAs($this->admin())
            ->put($this->url(), ['tenancy_rename_redirect_days' => '30'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->storedState('tenancy.rename.redirect_days'));
    }

    public function test_only_an_administrator_opens_and_saves_the_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get($this->url())->assertForbidden();
        $this->actingAs($user)->put($this->url(), [self::FIELD => '300'])->assertForbidden();

        $this->assertFalse($this->storedState(self::KEY));
    }

    public function test_the_platform_support_user_is_refused_even_as_an_administrator(): void
    {
        // Without a support session the console signs this user out first; the screen refuses it on its own as well.
        $this->withoutMiddleware(EndExpiredSupportSession::class);

        $support = $this->admin(['is_platform_support' => true]);

        $this->actingAs($support)->get($this->url())->assertForbidden();
        $this->actingAs($support)->put($this->url(), [self::FIELD => '300'])->assertForbidden();

        $this->assertFalse($this->storedState(self::KEY));
    }

    public function test_the_menu_entry_is_not_offered_to_a_user_who_is_not_an_administrator(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageSettings->value);

        $this->actingAs($user)
            ->get($this->panelUrl('/admin'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('navigation.groups', fn ($groups): bool => ! collect($groups)
                ->flatMap(static fn (array $group): array => $group['items'])
                ->contains('key', 'console.admin.platform-settings.edit'))->etc());
    }

    public function test_a_visitor_is_sent_to_the_sign_in(): void
    {
        $this->get($this->url())->assertRedirect(route('console.auth.login'));
    }

    private function storedState(string $key): bool
    {
        foreach ($this->freshSettings()->values() as $value) {
            if ($value->key === $key) {
                return $value->stored;
            }
        }

        return false;
    }

    private function freshSettings(): PlatformSettings
    {
        $this->app->forgetScopedInstances();

        return app(PlatformSettings::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function admin(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    private function url(): string
    {
        return $this->panelUrl('/admin/platform-settings');
    }
}
