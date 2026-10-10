<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\SystemTranslationCatalogInterface;
use App\Domains\Flow\Models\AssistantTranslation;
use App\Domains\Flow\Models\TenantTranslation;
use App\Domains\Flow\Translations\InMemorySystemTranslationCatalog;
use App\Domains\Flow\Translations\SystemTranslationEntry;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Settings\TenantSettings;
use Database\Seeders\TenantAclSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * The tenant's translations in the admin panel, served by the console shell in its admin mode: one override layer
 * (`tenant_translations`), the same page as the assistant's, and the permission Filament asked for.
 */
final class AdminTranslationsConsoleTest extends InertiaConsoleTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);

        TenantSettings::fake(['available_languages' => ['en', 'ru']]);

        $catalog = new InMemorySystemTranslationCatalog();
        $catalog->register(new SystemTranslationEntry('errors.fallback', 'errors', 'Fallback', ['en' => 'Something went wrong', 'ru' => 'Что-то пошло не так']));
        $this->app->instance(SystemTranslationCatalogInterface::class, $catalog);
    }

    public function test_the_list_shows_the_tenants_layer_inside_the_admin_shell(): void
    {
        TenantTranslation::query()->create(['tenant_id' => self::TENANT_ID, 'key' => 'errors.fallback', 'language' => 'ru', 'value' => 'Свой']);
        // An assistant's override is not the tenant's and does not show here.
        AssistantTranslation::query()->create(['assistant_id' => Assistant::factory()->create()->getKey(), 'key' => 'errors.fallback', 'language' => 'en', 'value' => 'Assistant']);

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Translations/Index')
                ->where('navigation.mode', 'admin')
                ->where('layered', false)
                ->where('languages', ['en', 'ru'])
                ->where('urls.index', '/admin/translations')
                ->where('table.rows.0.hasOverride', true)
                ->where('table.rows.0.languages', [
                    ['language' => 'en', 'value' => 'Something went wrong', 'status' => 'default', 'override' => '', 'inherited' => null, 'default' => 'Something went wrong'],
                    ['language' => 'ru', 'value' => 'Свой', 'status' => 'override', 'override' => 'Свой', 'inherited' => null, 'default' => 'Что-то пошло не так'],
                ])
                ->where('table.rows.0.updateUrl', '/admin/translations/errors.fallback')
                ->etc());
    }

    public function test_the_admin_menu_links_to_the_page_inside_the_shell(): void
    {
        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('navigation.groups', fn ($groups): bool => collect($groups)
                    ->flatMap(static fn (array $group): array => $group['items'])
                    ->contains(static fn (array $item): bool => 'filament.admin.pages.translations' === $item['key'] && false === $item['external']))
                ->etc());
    }

    public function test_saving_and_resetting_write_the_tenants_layer(): void
    {
        $translator = $this->spy(ContentTranslatorInterface::class);

        $this->actingAs($this->admin());

        $this->put($this->url('/errors.fallback'), ['values' => ['en' => 'Own', 'ru' => null]])
            ->assertRedirect($this->url())
            ->assertInertiaFlash('success', trans('console.translations.saved'));

        $this->assertSame(['en' => 'Own'], TenantTranslation::query()->where('tenant_id', self::TENANT_ID)->pluck('value', 'language')->all());
        $translator->shouldHaveReceived('invalidate')->with(self::TENANT_ID, 'en');
        $translator->shouldNotHaveReceived('invalidateAssistant');

        $this->delete($this->url('/errors.fallback'))
            ->assertRedirect($this->url())
            ->assertInertiaFlash('success', trans('console.translations.reset_done'));

        $this->assertSame(0, TenantTranslation::query()->count());
        $this->delete($this->url('/no.such.key'))->assertNotFound();
    }

    public function test_only_the_translations_permission_opens_the_page(): void
    {
        $allowed = User::factory()->create();
        $allowed->givePermissionTo(Permission::ManageTranslations->value);
        $this->actingAs($allowed)->get($this->url())->assertOk();

        $denied = User::factory()->create();
        $this->actingAs($denied)->get($this->url())->assertForbidden();
        $this->actingAs($denied)->put($this->url('/errors.fallback'), ['values' => ['en' => 'x']])->assertForbidden();
        $this->actingAs($denied)->delete($this->url('/errors.fallback'))->assertForbidden();

        $this->assertSame(0, TenantTranslation::query()->count());
    }

    public function test_a_visitor_is_sent_to_the_sign_in(): void
    {
        $this->get($this->url())->assertRedirect(route('filament.admin.auth.login'));
    }

    private function url(string $suffix = ''): string
    {
        return $this->panelUrl("/admin/translations{$suffix}");
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }
}
