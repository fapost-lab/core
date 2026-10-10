<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Settings\TenantSettings;
use Database\Seeders\TenantAclSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * The tenant's settings in the admin panel, served by the console shell in its admin mode: the fields, rules and
 * permission of the Filament page, and the content base language locked once the tenant has a flow definition.
 */
final class AdminTenantSettingsConsoleTest extends InertiaConsoleTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);

        $settings                        = app(TenantSettings::class);
        $settings->content_base_language = 'en';
        $settings->available_languages   = ['en', 'ru'];
        $settings->fallback_language     = 'en';
        $settings->webhook_timeout       = 7;
        $settings->save();
    }

    public function test_the_form_shows_the_stored_settings_inside_the_admin_shell(): void
    {
        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/TenantSettings/Edit')
                ->where('navigation.mode', 'admin')
                ->where('settings.content_base_language', 'en')
                ->where('settings.available_languages', ['en', 'ru'])
                ->where('settings.fallback_language', 'en')
                ->where('settings.flow_session_ttl', 86400)
                ->where('settings.broadcast_backpressure', true)
                ->where('baseLanguageLocked', false)
                ->where('options.languages', fn ($languages): bool => collect($languages)->contains('value', 'uk'))
                ->where('urls.submit', '/admin/tenant-settings')
                ->where('navigation.groups', fn ($groups): bool => collect($groups)
                    ->flatMap(static fn (array $group): array => $group['items'])
                    ->contains(static fn (array $item): bool => 'filament.admin.pages.tenant-settings' === $item['key'] && false === $item['external']))
                ->etc());
    }

    public function test_saving_writes_every_field_and_leaves_the_rest_alone(): void
    {
        $this->actingAs($this->admin())
            ->put($this->url(), $this->payload([
                'content_base_language' => 'ru',
                'available_languages'   => ['ru', 'uk', 'ru', ''],
                'fallback_language'     => 'uk',
                'flow_fallback_message' => null,
            ]))
            ->assertRedirect($this->url())
            ->assertInertiaFlash('success', trans('console.tenant_settings.saved'));

        $settings = $this->freshSettings();
        $this->assertSame('ru', $settings->content_base_language);
        $this->assertSame(['ru', 'uk'], $settings->available_languages);
        $this->assertSame('uk', $settings->fallback_language);
        $this->assertSame(45, $settings->messaging_rate_limit);
        $this->assertSame(250, $settings->broadcast_chunk_size);
        $this->assertFalse($settings->broadcast_backpressure);
        $this->assertSame(3600, $settings->flow_session_ttl);
        $this->assertSame(0, $settings->max_retry_attempts);
        $this->assertSame('', $settings->flow_fallback_message);
        $this->assertSame(7, $settings->webhook_timeout);
    }

    public function test_the_filament_rules_hold(): void
    {
        $this->actingAs($this->admin());

        $this->put($this->url(), $this->payload([
            'available_languages'  => [],
            'messaging_rate_limit' => 0,
            'broadcast_chunk_size' => 0,
            'flow_session_ttl'     => 59,
            'max_retry_attempts'   => -1,
        ]))->assertSessionHasErrors(['available_languages', 'messaging_rate_limit', 'broadcast_chunk_size', 'flow_session_ttl', 'max_retry_attempts']);

        $this->put($this->url(), $this->payload(['available_languages' => ['']]))->assertSessionHasErrors(['available_languages']);

        $this->put($this->url(), $this->payload(['available_languages' => ['en', 'xx'], 'content_base_language' => 'xx']))
            ->assertSessionHasErrors(['available_languages.1', 'content_base_language']);

        $this->put($this->url(), $this->payload(['available_languages' => ['en'], 'fallback_language' => 'ru']))
            ->assertSessionHasErrors(['fallback_language' => trans('staff.tenant_settings.errors.fallback_not_in_available')]);

        $this->assertSame(['en', 'ru'], $this->freshSettings()->available_languages);
    }

    public function test_the_base_language_is_locked_once_the_tenant_has_a_flow(): void
    {
        $flow = FlowDraft::factory()->create(['assistant_id' => Assistant::factory()->create()->getKey()]);
        FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => $flow->flow_id,
            'version'   => 1,
            'name'      => $flow->name,
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        $this->actingAs($this->admin());

        $this->get($this->url())->assertInertia(fn (AssertableInertia $page) => $page->where('baseLanguageLocked', true)->etc());

        $this->put($this->url(), $this->payload(['content_base_language' => 'ru']))
            ->assertSessionHasErrors(['content_base_language' => trans('staff.tenant_settings.errors.base_lang_locked')]);
        $this->assertSame('en', $this->freshSettings()->content_base_language);

        // The other fields still save with the base language as it is.
        $this->put($this->url(), $this->payload(['content_base_language' => 'en', 'messaging_rate_limit' => 5]))->assertSessionHasNoErrors();
        $this->assertSame(5, $this->freshSettings()->messaging_rate_limit);
    }

    public function test_only_the_settings_permission_opens_and_saves_the_page(): void
    {
        $allowed = User::factory()->create();
        $allowed->givePermissionTo(Permission::ManageSettings->value);
        $this->actingAs($allowed)->get($this->url())->assertOk();

        $denied = User::factory()->create();
        $this->actingAs($denied)->get($this->url())->assertForbidden();
        $this->actingAs($denied)->put($this->url(), $this->payload(['messaging_rate_limit' => 99]))->assertForbidden();

        $this->assertSame(30, $this->freshSettings()->messaging_rate_limit);
    }

    public function test_a_visitor_is_sent_to_the_sign_in(): void
    {
        $this->get($this->url())->assertRedirect(route('filament.admin.auth.login'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'content_base_language'  => 'en',
            'available_languages'    => ['en', 'ru'],
            'fallback_language'      => 'en',
            'messaging_rate_limit'   => 45,
            'broadcast_chunk_size'   => 250,
            'broadcast_backpressure' => false,
            'flow_session_ttl'       => 3600,
            'max_retry_attempts'     => 0,
            'flow_fallback_message'  => 'Sorry.',
        ], $overrides);
    }

    private function freshSettings(): TenantSettings
    {
        $this->app->forgetInstance(TenantSettings::class);

        return app(TenantSettings::class)->refresh();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    private function url(): string
    {
        return $this->panelUrl('/admin/tenant-settings');
    }
}
