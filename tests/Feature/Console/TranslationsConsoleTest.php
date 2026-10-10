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
 * The assistant's translations on the Inertia console: the catalog as a list with three cell states (own override,
 * the tenant's override as inherited, the default), its search, filter, sort and pages, the writes that touch only the
 * assistant's layer, and who may open it.
 */
final class TranslationsConsoleTest extends InertiaConsoleTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->assistant = Assistant::factory()->create();

        TenantSettings::fake(['available_languages' => ['ru', 'uk']]);

        $catalog = new InMemorySystemTranslationCatalog();
        $catalog->register(new SystemTranslationEntry('errors.fallback', 'errors', ['en' => 'Fallback', 'ru' => 'Запасной'], ['en' => 'Something went wrong', 'ru' => 'Что-то пошло не так']));
        $catalog->register(new SystemTranslationEntry('commands.reset.response', 'commands', 'Reset reply', ['en' => 'Reset done']));
        $catalog->register(new SystemTranslationEntry('errors.session_expired', 'errors', 'Session expired', ['en' => 'Session expired']));
        $this->app->instance(SystemTranslationCatalogInterface::class, $catalog);
    }

    public function test_the_list_layers_the_assistant_over_the_tenant_over_the_default(): void
    {
        $this->tenantOverride('errors.fallback', 'uk', 'Щось пішло не так (тенант)');
        $this->tenantOverride('errors.fallback', 'ru', 'Тенант');
        $this->assistantOverride('errors.fallback', 'ru', 'Ассистент');

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Translations/Index')
                ->where('languages', ['en', 'ru', 'uk'])
                ->where('groups', ['commands', 'errors'])
                ->where('layered', true)
                ->where('urls.index', "/assistant/{$this->assistant->getKey()}/translations")
                ->where('table.meta.total', 3)
                ->where('table.state', ['search' => '', 'sort' => 'key', 'perPage' => 25, 'filters' => []])
                ->where('table.rows.0.key', 'commands.reset.response')
                ->where('table.rows.0.hasOverride', false)
                ->where('table.rows.1.key', 'errors.fallback')
                ->where('table.rows.1.group', 'errors')
                ->where('table.rows.1.hasOverride', true)
                ->where('table.rows.1.languages', [
                    ['language' => 'en', 'value' => 'Something went wrong', 'status' => 'default', 'override' => '', 'inherited' => null, 'default' => 'Something went wrong'],
                    ['language' => 'ru', 'value' => 'Ассистент', 'status' => 'override', 'override' => 'Ассистент', 'inherited' => 'Тенант', 'default' => 'Что-то пошло не так'],
                    ['language' => 'uk', 'value' => 'Щось пішло не так (тенант)', 'status' => 'inherited', 'override' => '', 'inherited' => 'Щось пішло не так (тенант)', 'default' => 'Something went wrong'],
                ])
                ->where('table.rows.1.updateUrl', "/assistant/{$this->assistant->getKey()}/translations/errors.fallback")
                ->where('table.rows.1.resetUrl', "/assistant/{$this->assistant->getKey()}/translations/errors.fallback")
                ->etc());
    }

    public function test_another_assistants_overrides_do_not_show(): void
    {
        $other = Assistant::factory()->create();
        AssistantTranslation::query()->create(['assistant_id' => $other->getKey(), 'key' => 'errors.fallback', 'language' => 'en', 'value' => 'Other']);

        $this->actingAs($this->admin())
            ->get($this->url('?search=errors.fallback'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows.0.hasOverride', false)
                ->where('table.rows.0.languages.0.status', 'default')
                ->etc());
    }

    public function test_the_list_searches_filters_by_group_sorts_and_ignores_unknown_values(): void
    {
        $this->actingAs($this->admin());

        $this->get($this->url('?search=EXPIRED'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.key', 'errors.session_expired')
                ->etc());

        // The description is searched too.
        $this->get($this->url('?search=reset+reply'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.rows.0.key', 'commands.reset.response')->etc());

        $this->get($this->url('?filter[group]=errors&sort=-key'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 2)
                ->where('table.state.filters', ['group' => 'errors'])
                ->where('table.rows.0.key', 'errors.session_expired')
                ->etc());

        $this->get($this->url('?filter[group]=nope&sort=value;drop&per_page=7'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 3)
                ->where('table.state', ['search' => '', 'sort' => 'key', 'perPage' => 25, 'filters' => []])
                ->etc());
    }

    public function test_saving_sets_and_clears_the_assistants_overrides_only(): void
    {
        $this->tenantOverride('errors.fallback', 'en', 'Tenant text');
        $this->assistantOverride('errors.fallback', 'uk', 'Старе');

        $translator = $this->spy(ContentTranslatorInterface::class);

        $this->actingAs($this->admin())
            ->from($this->url('?page=1'))
            ->put($this->url('/errors.fallback'), ['values' => ['en' => '  Assistant text  ', 'ru' => 'Свой', 'uk' => '', 'de' => 'ignored']])
            ->assertRedirect($this->url('?page=1'))
            ->assertInertiaFlash('success', trans('console.translations.saved'));

        $this->assertSame(
            ['en' => 'Assistant text', 'ru' => 'Свой'],
            AssistantTranslation::query()->where('assistant_id', $this->assistant->getKey())->orderBy('language')->pluck('value', 'language')->all(),
        );
        // The tenant's layer is untouched.
        $this->assertSame(['en' => 'Tenant text'], TenantTranslation::query()->pluck('value', 'language')->all());

        $translator->shouldHaveReceived('invalidateAssistant')->with((string) $this->assistant->getKey(), 'uk');
        $translator->shouldNotHaveReceived('invalidate');
    }

    public function test_resetting_removes_the_assistants_overrides_of_the_key(): void
    {
        $this->tenantOverride('errors.fallback', 'en', 'Tenant text');
        $this->assistantOverride('errors.fallback', 'en', 'Own');
        $this->assistantOverride('errors.fallback', 'ru', 'Свой');
        $this->assistantOverride('errors.session_expired', 'en', 'Kept');

        $this->actingAs($this->admin())
            ->delete($this->url('/errors.fallback'))
            ->assertRedirect($this->url())
            ->assertInertiaFlash('success', trans('console.translations.reset_done'));

        $this->assertSame(['errors.session_expired'], AssistantTranslation::query()->pluck('key')->all());
        $this->assertSame(1, TenantTranslation::query()->count());
    }

    public function test_writes_validate_the_values_and_refuse_an_unknown_key(): void
    {
        $this->actingAs($this->admin());

        $this->put($this->url('/errors.fallback'), ['values' => ['en' => ['nested']]])->assertSessionHasErrors('values.en');
        $this->put($this->url('/errors.fallback'), [])->assertSessionHasErrors('values');
        $this->put($this->url('/no.such.key'), ['values' => ['en' => 'x']])->assertNotFound();
        $this->delete($this->url('/no.such.key'))->assertNotFound();

        $this->assertSame(0, AssistantTranslation::query()->count());
    }

    public function test_the_permission_opens_the_screen_and_its_absence_does_not(): void
    {
        $allowed = $this->userWith([Permission::ManageAssistants, Permission::ManageTranslations]);
        $this->actingAs($allowed)->get($this->url())->assertOk();

        $denied = $this->userWith([Permission::ManageAssistants]);
        $this->actingAs($denied)->get($this->url())->assertForbidden();
        $this->actingAs($denied)->put($this->url('/errors.fallback'), ['values' => ['en' => 'x']])->assertForbidden();
        $this->actingAs($denied)->delete($this->url('/errors.fallback'))->assertForbidden();

        $this->assertSame(0, AssistantTranslation::query()->count());
    }

    public function test_an_assistant_the_user_is_not_assigned_to_is_not_found(): void
    {
        $user       = $this->userWith([Permission::ManageAssistants, Permission::ManageTranslations]);
        $unassigned = Assistant::factory()->create();

        $this->actingAs($user)
            ->get($this->panelUrl("/assistant/{$unassigned->getKey()}/translations"))
            ->assertNotFound();
    }

    private function url(string $suffix = ''): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/translations{$suffix}");
    }

    private function tenantOverride(string $key, string $language, string $value): void
    {
        TenantTranslation::query()->create(['tenant_id' => self::TENANT_ID, 'key' => $key, 'language' => $language, 'value' => $value]);
    }

    private function assistantOverride(string $key, string $language, string $value): void
    {
        AssistantTranslation::query()->create(['assistant_id' => $this->assistant->getKey(), 'key' => $key, 'language' => $language, 'value' => $value]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(static fn (Permission $permission): string => $permission->value, $permissions));
        $user->assistants()->attach($this->assistant);

        return $user;
    }
}
