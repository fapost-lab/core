<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Http\Controllers\Console\Auth\LoginController;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Feature\FeatureTestCase;

/**
 * `UI_INERTIA` off (the default): no new route is registered and Filament answers every name.
 * The "on" half of this table is {@see ConsoleRouteInterceptionTest}.
 */
final class ConsoleSwitchTest extends FeatureTestCase
{
    public function test_the_switch_is_off_by_default(): void
    {
        $this->assertFalse(config('ui.inertia'));
    }

    public function test_filament_answers_the_login_names(): void
    {
        foreach (['filament.admin.auth.login', 'filament.assistant.auth.login'] as $name) {
            $action = $this->route($name)->getActionName();

            $this->assertStringNotContainsString('App\\Http\\Controllers\\Console', $action, $name);
            $this->assertStringContainsString('Filament', $action, $name);
        }
    }

    public function test_filament_answers_the_assistant_dashboard(): void
    {
        $action = $this->route('filament.assistant.pages.dashboard')->getActionName();

        $this->assertStringNotContainsString('App\\Http\\Controllers\\Console', $action);
        $this->assertStringContainsString('Filament', $action);
    }

    public function test_filament_answers_the_flow_and_flow_group_screens(): void
    {
        foreach ([
            'filament.assistant.resources.contacts.index',
            'filament.assistant.resources.contacts.view',
            'filament.assistant.resources.flows.index',
            'filament.assistant.resources.flows.create',
            'filament.assistant.resources.flows.edit',
            'filament.assistant.resources.flow-groups.index',
            'filament.assistant.resources.flow-groups.create',
            'filament.assistant.resources.flow-groups.edit',
            'filament.assistant.resources.broadcasts.index',
            'filament.assistant.resources.broadcasts.create',
            'filament.assistant.resources.broadcasts.edit',
            // Assistant settings.
            'filament.assistant.pages.settings',
        ] as $name) {
            $action = $this->route($name)->getActionName();

            $this->assertStringNotContainsString('App\\Http\\Controllers\\Console', $action, $name);
            $this->assertStringContainsString('Filament', $action, $name);
        }
    }

    public function test_the_builder_page_is_named_with_the_switch_off_too(): void
    {
        $this->assertNotNull(Route::getRoutes()->getByName('builder.flows.show'));
    }

    public function test_no_console_route_is_registered(): void
    {
        foreach ([
            'console.auth.login.attempt',
            'console.auth.logout',
            'console.locale.update',
            'console.contacts.tags',
            'console.contacts.groups',
            'console.flows.store',
            'console.broadcasts.store',
            'console.broadcasts.send',
            'console.broadcasts.reach',
            'console.flows.update',
            'console.flows.activity',
            'console.flows.destroy',
            'console.flows.destroy-many',
            'console.flow-groups.store',
            'console.flow-groups.store-inline',
            'console.flow-groups.update',
            'console.flow-groups.destroy',
            'console.flow-groups.destroy-many',
            // Assistant settings.
            'console.settings.update',
            'console.settings.store-flow',
        ] as $name) {
            $this->assertNull(Route::getRoutes()->getByName($name), $name);
        }

        $this->assertNotInstanceOf(LoginController::class, $this->route('filament.admin.auth.login')->getController());
    }

    public function test_filament_answers_the_translations_pages_and_their_writes_do_not_exist(): void
    {
        foreach (['filament.admin.pages.translations', 'filament.assistant.pages.translations'] as $name) {
            $action = $this->route($name)->getActionName();

            $this->assertStringNotContainsString('App\\Http\\Controllers', $action, $name);
            $this->assertStringContainsString('Filament', $action, $name);
        }

        foreach (['console.translations.update', 'console.translations.reset', 'console.admin.translations.update', 'console.admin.translations.reset'] as $name) {
            $this->assertNull(Route::getRoutes()->getByName($name), $name);
        }
    }

    public function test_the_admin_login_is_still_the_livewire_page(): void
    {
        $this->get($this->panelUrl('/admin/login'))
            ->assertOk()
            ->assertSee('wire:snapshot', false);
    }

    private function route(string $name): RoutingRoute
    {
        $route = Route::getRoutes()->getByName($name);
        $this->assertNotNull($route, "Route [{$name}] is not registered.");

        return $route;
    }
}
