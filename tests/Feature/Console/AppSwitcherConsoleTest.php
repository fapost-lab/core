<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\FeatureTestCase;

/**
 * The app switcher: which applications the rail offers (today's panel access), the user's own pinning and order, and
 * the search palette finding applications and their pages.
 */
final class AppSwitcherConsoleTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);

        Route::middleware('admin')->get('admin/_probe', fn () => Inertia::render('Auth/Login', ['action' => 'probe']));
        Route::middleware('console')->get('assistant/{assistant}/_probe', fn () => Inertia::render('Auth/Login', ['action' => 'probe']));
    }

    public function test_the_rail_offers_the_console_and_the_admin_panel_pinned_by_default(): void
    {
        $assistant = Assistant::factory()->create(['name' => 'Alpha']);

        $applications = $this->applications($this->actingAs($this->admin())->get($this->panelUrl('/admin/_probe')));

        $this->assertSame('admin', $applications['current']);
        $this->assertSame(['console', 'admin'], array_column($applications['items'], 'key'));
        $this->assertSame(['console', 'admin'], $applications['rail']);
        $this->assertSame('/console/applications/rail', $applications['railUrl']);
        $this->assertSame([
            'key'          => 'console',
            'label'        => 'Console',
            'short'        => 'Console',
            'scope'        => 'Per assistant',
            'icon'         => 'message-square',
            'href'         => "/assistant/{$assistant->getKey()}/dashboard",
            'external'     => false,
            'perAssistant' => true,
        ], $applications['items'][0]);
        $this->assertSame('/admin', $applications['items'][1]['href']);
        $this->assertFalse($applications['items'][1]['perAssistant']);
    }

    public function test_the_console_is_offered_only_to_a_user_with_an_assistant_to_open(): void
    {
        Assistant::factory()->create();
        $user = User::factory()->create();

        $applications = $this->applications($this->actingAs($user)->get($this->panelUrl('/admin/_probe')));

        $this->assertSame(['admin'], array_column($applications['items'], 'key'));
        $this->assertSame(['admin'], $applications['rail']);
    }

    public function test_the_console_opens_again_on_the_assistant_the_user_last_worked_in(): void
    {
        $alpha = Assistant::factory()->create(['name' => 'Alpha']);
        $beta  = Assistant::factory()->create(['name' => 'Beta']);
        $user  = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);
        $user->assistants()->attach([$alpha->getKey(), $beta->getKey()]);

        $this->actingAs($user);

        $this->assertSame("/assistant/{$alpha->getKey()}/dashboard", $this->consoleHref($this->get($this->panelUrl('/admin/_probe'))));

        $inConsole = $this->applications($this->get($this->panelUrl("/assistant/{$beta->getKey()}/_probe")));
        $this->assertSame('console', $inConsole['current']);
        $this->assertSame("/assistant/{$beta->getKey()}/dashboard", $inConsole['items'][0]['href']);

        $this->assertSame("/assistant/{$beta->getKey()}/dashboard", $this->consoleHref($this->get($this->panelUrl('/admin/_probe'))));

        // An assistant the user may no longer open is not where the Console leads.
        $user->assistants()->detach($beta->getKey());

        $this->assertSame("/assistant/{$alpha->getKey()}/dashboard", $this->consoleHref($this->get($this->panelUrl('/admin/_probe'))));
    }

    public function test_each_user_pins_and_orders_their_own_rail(): void
    {
        Assistant::factory()->create();
        $user  = $this->admin();
        $other = $this->admin();

        $this->actingAs($user)
            ->from($this->panelUrl('/admin/_probe'))
            ->put($this->panelUrl('/console/applications/rail'), ['applications' => ['admin', 'console']])
            ->assertRedirect($this->panelUrl('/admin/_probe'));

        $this->assertSame(['admin', 'console'], $user->refresh()->pinned_applications);
        $this->assertSame(['admin', 'console'], $this->applications($this->get($this->panelUrl('/admin/_probe')))['rail']);

        $this->put($this->panelUrl('/console/applications/rail'), ['applications' => []])->assertRedirect();

        $applications = $this->applications($this->get($this->panelUrl('/admin/_probe')));
        $this->assertSame([], $applications['rail']);
        $this->assertSame(['console', 'admin'], array_column($applications['items'], 'key'), 'Unpinned applications stay in "All applications".');

        $this->assertNull($other->refresh()->pinned_applications);
        $this->assertSame(['console', 'admin'], $this->applications($this->actingAs($other)->get($this->panelUrl('/admin/_probe')))['rail']);
    }

    public function test_the_rail_takes_only_distinct_applications_the_user_may_open(): void
    {
        Assistant::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user);

        foreach ([['console'], ['admin', 'admin'], ['feedback'], 'admin', null] as $applications) {
            $errors = $this->putJson($this->panelUrl('/console/applications/rail'), ['applications' => $applications])
                ->assertUnprocessable()
                ->json('errors');

            $this->assertStringStartsWith('applications', (string) array_key_first($errors));
        }

        $this->assertNull($user->refresh()->pinned_applications);
    }

    public function test_an_application_the_user_cannot_open_for_now_stays_pinned(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['pinned_applications' => ['console', 'admin']])->save();

        $this->actingAs($user);

        $this->assertSame(['admin'], $this->applications($this->get($this->panelUrl('/admin/_probe')))['rail']);

        $this->put($this->panelUrl('/console/applications/rail'), ['applications' => ['admin']])->assertRedirect();

        $this->assertSame(['admin', 'console'], $user->refresh()->pinned_applications);
    }

    public function test_a_guest_cannot_change_a_rail(): void
    {
        $this->putJson($this->panelUrl('/console/applications/rail'), ['applications' => ['admin']])->assertUnauthorized();
    }

    public function test_the_palette_finds_applications_and_the_pages_of_each(): void
    {
        $assistant = Assistant::factory()->create();

        $this->actingAs($this->admin());

        $this->getJson($this->searchUrl('console'))
            ->assertOk()
            ->assertJsonPath('groups.0', ['key' => 'applications', 'items' => [[
                'id'       => 'console',
                'title'    => 'Console',
                'subtitle' => 'Per assistant',
                'url'      => "/assistant/{$assistant->getKey()}/dashboard",
                'external' => false,
            ]]]);

        $pages = $this->getJson($this->searchUrl('dashb'))->assertOk()->json('groups');

        $this->assertSame('pages', $pages[0]['key']);
        $this->assertSame(['Console', 'Admin panel'], array_column($pages[0]['items'], 'subtitle'));
        $this->assertSame(["/assistant/{$assistant->getKey()}/dashboard", '/admin'], array_column($pages[0]['items'], 'url'));
    }

    public function test_the_palette_finds_only_pages_the_menu_shows_the_user(): void
    {
        $assistant = Assistant::factory()->create();
        $user      = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);
        $user->assistants()->attach($assistant);

        $this->actingAs($user);

        $this->getJson($this->searchUrl('flow'))->assertOk()->assertExactJson(['groups' => []]);

        $user->givePermissionTo(Permission::ManageFlowDefinitions->value);

        $titles = array_column($this->getJson($this->searchUrl('flow'))->json('groups.0.items'), 'title');
        $this->assertContains('Flows', $titles);

        // Without an assistant to open, the Console and its pages are not found.
        $user->assistants()->detach($assistant->getKey());

        $this->getJson($this->searchUrl('flow'))->assertOk()->assertExactJson(['groups' => []]);
    }

    public function test_the_palette_finds_the_console_pages_of_the_tab_that_asks(): void
    {
        $alpha   = Assistant::factory()->create(['name' => 'Alpha']);
        $beta    = Assistant::factory()->create(['name' => 'Beta']);
        $foreign = Assistant::factory()->create(['name' => 'Gamma']);
        $user    = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);
        $user->assistants()->attach([$alpha->getKey(), $beta->getKey()]);

        $this->actingAs($user);

        // Tab one works in Alpha, then tab two opens Beta: the session now remembers Beta.
        $this->get($this->panelUrl("/assistant/{$alpha->getKey()}/_probe"))->assertOk();
        $this->get($this->panelUrl("/assistant/{$beta->getKey()}/_probe"))->assertOk();

        $this->assertSame("/assistant/{$alpha->getKey()}/dashboard", $this->consoleDashboardHit(['assistant' => (string) $alpha->getKey()]));
        $this->assertSame("/assistant/{$beta->getKey()}/dashboard", $this->consoleDashboardHit([]));
        // An assistant the user may not open is ignored, and the one last worked in is used.
        $this->assertSame("/assistant/{$beta->getKey()}/dashboard", $this->consoleDashboardHit(['assistant' => (string) $foreign->getKey()]));
        $this->assertSame("/assistant/{$beta->getKey()}/dashboard", $this->consoleDashboardHit(['assistant' => 'not-an-id']));
    }

    public function test_finding_the_consoles_assistant_does_not_grow_with_the_number_of_assistants(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);
        $user->assistants()->attach(Assistant::factory()->count(2)->create()->modelKeys());

        $this->actingAs($user);

        // Warm the caches a first request fills (permissions), so both measurements see the same.
        $this->get($this->panelUrl('/admin/_probe'))->assertOk();
        $this->getJson($this->searchUrl('dashb'))->assertOk();

        $few = [$this->queries(fn () => $this->get($this->panelUrl('/admin/_probe'))->assertOk()), $this->queries(fn () => $this->getJson($this->searchUrl('dashb'))->assertOk())];

        $user->assistants()->attach(Assistant::factory()->count(25)->create()->modelKeys());

        $many = [$this->queries(fn () => $this->get($this->panelUrl('/admin/_probe'))->assertOk()), $this->queries(fn () => $this->getJson($this->searchUrl('dashb'))->assertOk())];

        $this->assertGreaterThan(0, $few[0], 'The listener counts the queries.');
        $this->assertSame($few, $many);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function consoleDashboardHit(array $query): string
    {
        $items = $this->getJson($this->panelUrl('/admin/search?' . http_build_query(['q' => 'dashb', ...$query])))
            ->assertOk()
            ->json('groups.0.items');

        $this->assertSame('Console', $items[0]['subtitle']);

        return $items[0]['url'];
    }

    private function queries(callable $request): int
    {
        $count = 0;
        DB::listen(static function () use (&$count): void {
            $count++;
        });

        $request();

        // Each call counts into its own variable; the listener stays registered but changes nothing it returned.
        return $count;
    }

    /**
     * @param  TestResponse<Response>  $response
     *
     * @return array{current: string|null, items: list<array<string, mixed>>, rail: list<string>, railUrl: string}
     */
    private function applications(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('page')['props']['applications'];
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function consoleHref(TestResponse $response): string
    {
        $items = $this->applications($response)['items'];

        $this->assertSame('console', $items[0]['key']);

        return $items[0]['href'];
    }

    private function searchUrl(string $text): string
    {
        return $this->panelUrl('/admin/search?' . http_build_query(['q' => $text]));
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }
}
