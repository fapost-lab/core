<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Contracts\SystemTranslationCatalogInterface;
use App\Domains\Flow\Models\AssistantTranslation;
use App\Domains\Flow\Models\TenantTranslation;
use App\Domains\Flow\Translations\InMemorySystemTranslationCatalog;
use App\Domains\Flow\Translations\SystemTranslationEntry;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Settings\TenantSettings;
use App\Filament\Assistant\Pages\TranslationsPage as AssistantTranslationsPage;
use App\Filament\Pages\TranslationsPage;
use Database\Seeders\TenantAclSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTestCase;

/**
 * With the switch off the Filament translations pages still answer, now over the editor the console shares: the
 * tenant page shows its own layer, the assistant page its own over the tenant's.
 */
final class TranslationsPagesFilamentCompatibilityTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        // A Livewire test runs no middleware, so the tenant the panel's middleware would resolve is set here.
        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());
        TenantSettings::fake(['available_languages' => ['en', 'ru']]);

        $catalog = new InMemorySystemTranslationCatalog();
        $catalog->register(new SystemTranslationEntry('errors.fallback', 'errors', 'Fallback', ['en' => 'Catalog default text']));
        $this->app->instance(SystemTranslationCatalogInterface::class, $catalog);

        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);
        $this->actingAs($user);

        TenantTranslation::query()->create(['tenant_id' => self::TENANT_ID, 'key' => 'errors.fallback', 'language' => 'ru', 'value' => 'Tenant text']);
    }

    public function test_the_tenant_page_shows_its_overrides_and_the_defaults(): void
    {
        Filament::setCurrentPanel('admin');

        Livewire::test(TranslationsPage::class)
            ->assertOk()
            ->assertSee('Tenant text')
            ->assertSee('Catalog default text');
    }

    public function test_the_assistant_page_layers_its_overrides_over_the_tenants(): void
    {
        $assistant = Assistant::factory()->create();
        AssistantTranslation::query()->create(['assistant_id' => $assistant->getKey(), 'key' => 'errors.fallback', 'language' => 'en', 'value' => 'Assistant text']);

        Filament::setCurrentPanel('assistant');
        Filament::setTenant($assistant);
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        Livewire::test(AssistantTranslationsPage::class)
            ->assertOk()
            ->assertSee('Assistant text')
            ->assertSee('Tenant text')
            ->assertDontSee('Catalog default text');
    }

    public function test_editing_on_the_tenant_page_saves_an_override_and_an_empty_value_removes_it(): void
    {
        Filament::setCurrentPanel('admin');

        Livewire::test(TranslationsPage::class)
            ->callTableAction('edit', 'errors.fallback', data: ['values' => ['en' => '  New text  ', 'ru' => '']])
            ->assertHasNoTableActionErrors();

        $this->assertSame(
            ['en' => 'New text'],
            TenantTranslation::query()->where('key', 'errors.fallback')->pluck('value', 'language')->all(),
        );
        $this->assertSame(0, AssistantTranslation::query()->count());
    }

    public function test_resetting_on_the_tenant_page_removes_the_overrides_in_every_language(): void
    {
        TenantTranslation::query()->create(['tenant_id' => self::TENANT_ID, 'key' => 'errors.fallback', 'language' => 'en', 'value' => 'English override']);
        TenantTranslation::query()->create(['tenant_id' => self::TENANT_ID, 'key' => 'errors.other', 'language' => 'en', 'value' => 'Other key']);

        Filament::setCurrentPanel('admin');

        Livewire::test(TranslationsPage::class)
            ->callTableAction('reset', 'errors.fallback')
            ->assertHasNoTableActionErrors();

        $this->assertSame(0, TenantTranslation::query()->where('key', 'errors.fallback')->count());
        $this->assertSame(1, TenantTranslation::query()->where('key', 'errors.other')->count());
    }

    public function test_editing_on_the_assistant_page_writes_only_to_the_assistant_layer(): void
    {
        $assistant = $this->actAsAssistant();

        Livewire::test(AssistantTranslationsPage::class)
            ->callTableAction('edit', 'errors.fallback', data: ['values' => ['en' => 'Assistant text', 'ru' => '']])
            ->assertHasNoTableActionErrors();

        $this->assertSame(
            ['en' => 'Assistant text'],
            AssistantTranslation::query()->where('assistant_id', $assistant->getKey())->pluck('value', 'language')->all(),
        );
        $this->assertSame(
            ['ru' => 'Tenant text'],
            TenantTranslation::query()->where('key', 'errors.fallback')->pluck('value', 'language')->all(),
        );
    }

    public function test_resetting_on_the_assistant_page_leaves_the_tenant_layer_alone(): void
    {
        $assistant = $this->actAsAssistant();

        foreach (['en', 'ru'] as $language) {
            AssistantTranslation::query()->create(['assistant_id' => $assistant->getKey(), 'key' => 'errors.fallback', 'language' => $language, 'value' => "Assistant {$language}"]);
        }

        Livewire::test(AssistantTranslationsPage::class)
            ->callTableAction('reset', 'errors.fallback')
            ->assertHasNoTableActionErrors();

        $this->assertSame(0, AssistantTranslation::query()->count());
        $this->assertSame(1, TenantTranslation::query()->where('key', 'errors.fallback')->count());
    }

    private function actAsAssistant(): Assistant
    {
        $assistant = Assistant::factory()->create();

        Filament::setCurrentPanel('assistant');
        Filament::setTenant($assistant);
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        return $assistant;
    }
}
