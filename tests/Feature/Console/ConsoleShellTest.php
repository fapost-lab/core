<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\FeatureTestCase;

/**
 * The shell's props (side menu, assistant switcher, endpoints) and the language endpoint.
 */
final class ConsoleShellTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);

        Route::middleware('admin')->get('admin/_probe', fn () => Inertia::render('Auth/Login', ['action' => 'probe']));
        Route::middleware('console')->get('assistant/{assistant}/_probe', fn () => Inertia::render('Auth/Login', ['action' => 'probe']));
        Route::middleware('web')->get('_web_probe', fn () => Inertia::render('Auth/Login', ['action' => 'probe']));
    }

    public function test_an_administrator_sees_every_assistant_item_in_its_group_and_order(): void
    {
        $assistant = Assistant::factory()->create();

        $props = $this->props($this->actingAs($this->admin())->get($this->panelUrl("/assistant/{$assistant->getKey()}/_probe")));

        $this->assertSame('console', $props['navigation']['mode']);
        $this->assertSame(['Overview', 'Channels', 'Flow', 'Operations', 'Settings'], array_column($props['navigation']['groups'], 'label'));
        $this->assertSame(['console.dashboard'], $this->keys($props['navigation']['groups'][0]));
        $this->assertSame(['console.channels.index'], $this->keys($props['navigation']['groups'][1]));
        $this->assertSame([
            'console.flows.index',
            'console.flow-groups.index',
        ], $this->keys($props['navigation']['groups'][2]));
        $this->assertSame([
            'console.flow-sessions.index',
            'console.flow-logs.index',
            'console.contacts.index',
            'console.contact-groups.index',
            'console.conversations.index',
            'console.contact-segments.index',
            'console.broadcasts.index',
        ], $this->keys($props['navigation']['groups'][3]));
        $this->assertSame([
            'console.settings.edit',
            'console.translations.index',
        ], $this->keys($props['navigation']['groups'][4]));
        $this->assertSame("/assistant/{$assistant->getKey()}/dashboard", $props['navigation']['groups'][0]['items'][0]['href']);
    }

    public function test_every_core_item_is_an_inertia_link(): void
    {
        $assistant = Assistant::factory()->create();

        $groups = $this->props($this->actingAs($this->admin())->get($this->panelUrl("/assistant/{$assistant->getKey()}/_probe")))['navigation']['groups'];

        $this->assertFalse($groups[0]['items'][0]['external'], 'The dashboard.');
        $this->assertFalse($groups[1]['items'][0]['external'], 'Channels.');
        $this->assertFalse($groups[2]['items'][0]['external'], 'Flows.');
        $this->assertFalse($groups[2]['items'][1]['external'], 'Flow groups.');
        $this->assertFalse($groups[3]['items'][0]['external'], 'Flow sessions.');
        $this->assertFalse($groups[3]['items'][1]['external'], 'The flow log.');
        $this->assertFalse($groups[3]['items'][4]['external'], 'Conversations.');
        $this->assertNull($groups[3]['items'][4]['badge'], 'No unread conversations, no badge.');
    }

    public function test_a_user_sees_only_the_items_their_rights_open(): void
    {
        $assistant = Assistant::factory()->create();
        $user      = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);
        $user->givePermissionTo(Permission::ManageTranslations->value);
        $user->assistants()->attach($assistant);

        $groups = $this->props($this->actingAs($user)->get($this->panelUrl("/assistant/{$assistant->getKey()}/_probe")))['navigation']['groups'];

        $this->assertSame(
            ['console.dashboard', 'console.translations.index'],
            array_merge(...array_map($this->keys(...), $groups)),
        );
    }

    public function test_the_tenant_wide_menu_follows_the_admin_screens(): void
    {
        $props  = $this->props($this->actingAs($this->admin())->get($this->panelUrl('/admin/_probe')));
        $groups = $props['navigation']['groups'];

        $this->assertSame('admin', $props['navigation']['mode']);
        $this->assertNull($props['assistants']);
        $this->assertNull($groups[0]['label']);
        $this->assertSame(['console.admin.dashboard', 'console.admin.tenant-settings.edit'], $this->keys($groups[0]));
        $this->assertSame(['console.admin.assistants.index'], $this->keys($groups[1]));
        $this->assertSame(['console.admin.users.index', 'console.admin.roles.index', 'console.admin.support-access.index'], $this->keys($groups[2]));
        $this->assertSame(['console.admin.media.index', 'console.admin.translations.index'], $this->keys($groups[3]));
    }

    public function test_a_user_without_rights_sees_only_the_dashboard_in_the_tenant_wide_menu(): void
    {
        $groups = $this->props($this->actingAs(User::factory()->create())->get($this->panelUrl('/admin/_probe')))['navigation']['groups'];

        $this->assertSame(['console.admin.dashboard'], array_merge(...array_map($this->keys(...), $groups)));
    }

    public function test_the_switcher_lists_only_the_assistants_the_user_may_open(): void
    {
        $mine  = Assistant::factory()->create(['name' => 'Alpha']);
        $other = Assistant::factory()->create(['name' => 'Beta']);
        $open  = Assistant::factory()->create(['name' => 'Gamma']);
        $user  = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);
        $user->assistants()->attach([$mine->getKey(), $open->getKey()]);

        $switcher = $this->props($this->actingAs($user)->get($this->panelUrl("/assistant/{$open->getKey()}/_probe")))['assistants'];

        $this->assertSame((string) $open->getKey(), $switcher['current']['id']);
        $this->assertSame(['Alpha', 'Gamma'], array_column($switcher['items'], 'name'));
        $this->assertNotContains((string) $other->getKey(), array_column($switcher['items'], 'id'));
        $this->assertSame("/assistant/{$mine->getKey()}/dashboard", $switcher['items'][0]['href']);
        $this->assertFalse($switcher['items'][0]['external']);
        $this->assertArrayNotHasKey('back', $switcher, 'The way to the admin screens is the application rail.');
    }

    public function test_the_shell_props_exist_only_on_console_routes(): void
    {
        $this->actingAs($this->admin())
            ->get($this->panelUrl('/_web_probe'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('navigation')
                ->missing('assistants')
                ->missing('shell')
                ->missing('translations.console')
                ->etc());
    }

    public function test_the_shell_endpoints_and_translations_are_shared(): void
    {
        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin/_probe?lang=ru'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.localeUrl', '/console/locale')
                ->where('shell.logoutUrl', '/console/logout')
                ->where('shell.supportLeaveUrl', '/support/leave')
                ->where('shell.locales', ['en', 'ru', 'uk'])
                ->where('translations.console.user_menu.sign_out', 'Выйти')
                ->where('translations.console.close', 'Закрыть')
                ->where('translations.console.table.search', 'Поиск')
                ->etc());
    }

    public function test_the_language_is_stored_in_the_session_and_the_long_lived_cookie(): void
    {
        $response = $this->actingAs($this->admin())
            ->from($this->panelUrl('/admin/_probe'))
            ->post($this->panelUrl('/console/locale'), ['locale' => 'uk'], ['X-Inertia' => 'true']);

        $response->assertStatus(409)->assertHeader('X-Inertia-Location', $this->panelUrl('/admin/_probe'));
        $response->assertSessionHas('locale', 'uk');
        $response->assertCookie('filament_language_switcher_locale', 'uk');
    }

    public function test_the_language_is_kept_on_the_account_for_mail_and_notifications(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->post($this->panelUrl('/console/locale'), ['locale' => 'ru'], ['X-Inertia' => 'true']);

        $this->assertSame('ru', $user->fresh()->locale);
        $this->assertSame('ru', $user->fresh()->preferredLocale());
    }

    public function test_a_language_the_console_does_not_speak_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->postJson($this->panelUrl('/console/locale'), ['locale' => 'de'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('locale');
    }

    public function test_a_foreign_return_address_is_not_followed(): void
    {
        $this->actingAs($this->admin())
            ->from('https://evil.example/page')
            ->post($this->panelUrl('/console/locale'), ['locale' => 'ru'], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('console.admin.dashboard'));
    }

    public function test_a_guest_cannot_set_the_language(): void
    {
        $this->post($this->panelUrl('/console/locale'), ['locale' => 'ru'])->assertRedirect(route('console.auth.login'));
    }

    /**
     * @param  TestResponse<Response>  $response
     *
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    /**
     * @param  array{items: list<array{key: string}>}  $group
     *
     * @return list<string>
     */
    private function keys(array $group): array
    {
        return array_column($group['items'], 'key');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }
}
