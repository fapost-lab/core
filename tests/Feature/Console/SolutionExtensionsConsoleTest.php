<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\FeatureTestCase;
use Tests\Fixtures\Solutions\Showcase\ShowcaseColumnValues;
use Tests\Support\InstallsShowcaseSolution;

/**
 * The server half of the console extension contract, end to end with the fixture Solution `showcase`: what an active,
 * published Solution adds (its application, menu, routes, pages and the five other points in the shared prop
 * `extensions`), what the user's abilities and the open assistant hide, and that an inactive or unpublished Solution
 * adds nothing and answers 404.
 */
final class SolutionExtensionsConsoleTest extends FeatureTestCase
{
    use InstallsShowcaseSolution;

    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->installShowcase();

        Route::middleware('admin')->get('admin/_probe', fn () => Inertia::render('Auth/Login', ['action' => 'probe']));
        Route::middleware('console')->get('assistant/{assistant}/_probe', fn () => Inertia::render('Auth/Login', ['action' => 'probe']));

        $this->assistant = Assistant::factory()->create(['name' => 'Alpha']);
    }

    protected function tearDown(): void
    {
        $this->removeShowcase();

        parent::tearDown();
    }

    public function test_an_inactive_solution_adds_nothing_and_its_routes_answer_404(): void
    {
        $this->actingAs($this->admin());

        $props = $this->props($this->get($this->panelUrl('/admin/_probe')));

        $this->assertSame(['console', 'admin'], array_column($props['applications']['items'], 'key'));
        $this->assertSame($this->emptyExtensions(), $props['extensions']);

        $this->get($this->panelUrl('/apps/showcase/board'))->assertNotFound();
        $this->get($this->panelUrl("/assistant/{$this->assistant->getKey()}/apps/showcase/contacts/abc"))->assertNotFound();
        $this->assertStringContainsString('<script type="application/json" id="fapost-solutions">{}</script>', (string) $this->get($this->panelUrl('/admin/_probe'))->getContent());
    }

    public function test_an_active_solution_adds_its_application_after_cores_unpinned(): void
    {
        $this->activateShowcase();
        $this->actingAs($this->admin());

        $applications = $this->props($this->get($this->panelUrl('/admin/_probe')))['applications'];

        $this->assertSame(['console', 'admin', 'showcase'], array_column($applications['items'], 'key'));
        $this->assertSame(['console', 'admin'], $applications['rail']);
        $this->assertSame('admin', $applications['current']);
        $this->assertSame([
            'key'          => 'showcase',
            'label'        => 'Showcase board',
            'short'        => 'Showcase',
            'scope'        => __('console.applications.apps.admin.scope'),
            'icon'         => 'kanban',
            'href'         => '/apps/showcase/board',
            'external'     => false,
            'perAssistant' => false,
        ], $applications['items'][2]);
    }

    public function test_the_search_palette_finds_the_solutions_application_and_its_pages(): void
    {
        $this->activateShowcase();
        $this->actingAs($this->admin());

        $groups = $this->getJson($this->panelUrl('/admin/search?' . http_build_query(['q' => 'showcase'])))->assertOk()->json('groups');
        $pages  = $this->getJson($this->panelUrl('/admin/search?' . http_build_query(['q' => 'desk'])))->assertOk()->json('groups.0.items');

        $this->assertSame('/apps/showcase/board', $groups[0]['items'][0]['url']);
        $this->assertSame(['solutions.showcase.desk', '/apps/showcase/desk', 'Showcase board'], [$pages[0]['id'], $pages[0]['url'], $pages[0]['subtitle']]);
    }

    public function test_a_solution_page_renders_by_name_in_its_application_with_its_menu(): void
    {
        $this->activateShowcase();
        $this->actingAs($this->admin());

        $response = $this->get($this->panelUrl('/apps/showcase/board'))->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('showcase::Board/Index', false)->where('title', 'Board'));

        $props = $this->props($response);

        $this->assertSame('showcase', $props['applications']['current']);
        $this->assertSame('admin', $props['navigation']['mode']);
        $this->assertSame([
            ['label' => null, 'items' => [['key' => 'solutions.showcase.board', 'label' => 'Board', 'href' => '/apps/showcase/board', 'icon' => 'layout-grid', 'external' => false, 'badge' => null]]],
            ['label' => 'Administration', 'items' => [['key' => 'solutions.showcase.desk', 'label' => 'Desk', 'href' => '/apps/showcase/desk', 'icon' => 'lamp-desk', 'external' => false, 'badge' => null]]],
        ], $props['navigation']['groups']);
    }

    public function test_a_per_assistant_route_runs_on_the_assistant_of_the_url(): void
    {
        $this->activateShowcase();
        $this->actingAs($this->admin());

        $this->get($this->panelUrl("/assistant/{$this->assistant->getKey()}/apps/showcase/contacts/abc"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('showcase::Contact/Show', false)
                ->where('record', 'abc')
                ->where('assistant', (string) $this->assistant->getKey())
                ->where('navigation.mode', 'console'));
    }

    public function test_a_solution_route_rendering_a_page_outside_its_namespace_is_an_error(): void
    {
        $this->activateShowcase();
        $this->actingAs($this->admin());
        $this->withoutExceptionHandling();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('may render only its own pages');

        $this->get($this->panelUrl('/apps/showcase/foreign'));
    }

    public function test_an_inertia_visit_rendering_a_page_outside_its_namespace_is_an_error_too(): void
    {
        $this->activateShowcase();
        $this->actingAs($this->admin());
        $version = $this->version();
        $this->withoutExceptionHandling();

        $this->expectException(LogicException::class);

        $this->get($this->panelUrl('/apps/showcase/foreign'), ['X-Inertia' => 'true', 'X-Inertia-Version' => $version]);
    }

    public function test_a_deactivated_or_unpublished_solution_answers_404_again(): void
    {
        $this->activateShowcase();
        $this->actingAs($this->admin());
        $this->get($this->panelUrl('/apps/showcase/board'))->assertOk();

        $this->deactivateShowcase();
        $this->get($this->panelUrl('/apps/showcase/board'))->assertNotFound();

        // Active again, but its build was never published.
        $this->installShowcase(publish: false);
        $this->activateShowcase();
        $this->get($this->panelUrl('/apps/showcase/board'))->assertNotFound();
        $this->assertSame(['console', 'admin'], array_column($this->props($this->get($this->panelUrl('/admin/_probe')))['applications']['items'], 'key'));
    }

    public function test_every_point_reaches_the_extensions_prop_translated_on_the_server(): void
    {
        $this->activateShowcase();
        $this->actingAs($this->admin());

        $extensions = $this->props($this->get($this->panelUrl("/assistant/{$this->assistant->getKey()}/_probe")))['extensions'];
        $template   = "/assistant/{$this->assistant->getKey()}/apps/showcase/contacts/__record__";

        $this->assertSame([['id' => 'showcase.helper', 'solution' => 'showcase', 'key' => 'helper', 'order' => 0, 'component' => 'showcase::Shell/Helper']], $extensions['shell']['floating']);
        $this->assertSame([['id' => 'showcase.status', 'solution' => 'showcase', 'key' => 'status', 'order' => 0, 'component' => 'showcase::Shell/Status']], $extensions['shell']['header']);
        $this->assertSame([
            ['id' => 'showcase.open', 'solution' => 'showcase', 'key' => 'open', 'order' => 0, 'kind' => 'link', 'label' => 'Open in Showcase', 'icon' => 'external-link', 'hrefTemplate' => $template],
            ['id' => 'showcase.secret', 'solution' => 'showcase', 'key' => 'secret', 'order' => 0, 'kind' => 'dialog', 'label' => 'Secret action', 'icon' => 'circle', 'component' => 'showcase::Actions/Note'],
        ], $extensions['actions']['contact.view']);
        $this->assertSame($template, $extensions['actions']['contact.row'][0]['hrefTemplate']);
        $this->assertSame(['kind' => 'dialog', 'label' => 'Add a note', 'component' => 'showcase::Actions/Note'], array_intersect_key($extensions['actions']['conversation.view'][0], array_flip(['kind', 'label', 'component'])));
        $this->assertSame([['id' => 'showcase.stats', 'solution' => 'showcase', 'key' => 'stats', 'order' => 0, 'component' => 'showcase::Widgets/Stats', 'size' => 'double']], $extensions['widgets']['console']);
        $this->assertSame([['id' => 'showcase.overview', 'solution' => 'showcase', 'key' => 'overview', 'order' => -5, 'component' => 'showcase::Widgets/Stats', 'size' => 'full']], $extensions['widgets']['admin']);
        $this->assertSame([['id' => 'showcase.general', 'solution' => 'showcase', 'key' => 'general', 'order' => 0, 'label' => 'Showcase', 'component' => 'showcase::Settings/Assistant']], $extensions['tabs']['assistant']);
        $this->assertSame('Showcase billing', $extensions['tabs']['tenant'][0]['label']);
        // Tones are a map on the client, an object even when empty.
        $this->assertSame(
            '[{"id":"showcase.score","solution":"showcase","key":"score","order":0,"label":"Score","cell":"number","tones":{}}]',
            json_encode($extensions['columns']['contacts'], JSON_THROW_ON_ERROR),
        );
        $this->assertSame('{"happy":"success","angry":"danger"}', json_encode($extensions['columns']['conversations'][0]['tones'], JSON_THROW_ON_ERROR));
    }

    public function test_abilities_and_the_open_assistant_decide_what_a_user_sees(): void
    {
        $this->activateShowcase();
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);
        $user->assistants()->attach($this->assistant);
        $this->actingAs($user);

        // Tenant-wide: no assistant, so neither the per-assistant shell element nor the links to assistant routes.
        $tenantWide = $this->props($this->get($this->panelUrl('/admin/_probe')))['extensions'];

        $this->assertSame([], $tenantWide['shell']['header']);
        $this->assertSame([], $tenantWide['actions']['contact.row']);
        $this->assertSame('showcase.helper', $tenantWide['shell']['floating'][0]['id']);

        // On the assistant: the header element and the link; never what `showcase.manage` guards.
        $onAssistant = $this->props($this->get($this->panelUrl("/assistant/{$this->assistant->getKey()}/_probe")))['extensions'];

        $this->assertSame(['showcase.open'], array_column($onAssistant['actions']['contact.view'], 'id'));
        $this->assertSame('showcase.status', $onAssistant['shell']['header'][0]['id']);
        $this->assertSame([], $onAssistant['widgets']['admin']);

        $menu = $this->props($this->get($this->panelUrl('/apps/showcase/board')))['navigation']['groups'];

        $this->assertSame(['solutions.showcase.board'], array_column(array_merge(...array_column($menu, 'items')), 'key'));
    }

    public function test_a_route_the_menu_hides_also_refuses_the_user(): void
    {
        $this->activateShowcase();
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);
        $this->actingAs($user);

        $this->get($this->panelUrl('/apps/showcase/board'))->assertOk();
        $this->get($this->panelUrl('/apps/showcase/desk'))->assertForbidden();

        $this->actingAs($this->admin());
        $this->get($this->panelUrl('/apps/showcase/desk'))->assertOk();
    }

    public function test_a_visitor_who_is_not_signed_in_gets_no_bundle_map(): void
    {
        $this->activateShowcase();

        $html = (string) $this->get($this->panelUrl('/admin/login'))->assertOk()->getContent();

        $this->assertStringContainsString('<script type="application/json" id="fapost-solutions">{}</script>', $html);
        $this->assertStringNotContainsString('showcase', $html);
    }

    public function test_a_solution_page_works_out_the_applications_once(): void
    {
        $this->activateShowcase();
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);
        $user->assistants()->attach($this->assistant);
        $this->actingAs($user);

        // Finding the assistant the Console opens on is one query on `assistants` per list of applications.
        $probe    = $this->assistantQueries(fn () => $this->get($this->panelUrl('/admin/_probe'))->assertOk());
        $solution = $this->assistantQueries(fn () => $this->get($this->panelUrl('/apps/showcase/board'))->assertOk());

        $this->assertGreaterThan(0, $probe);
        $this->assertSame($probe, $solution);
    }

    public function test_the_root_view_lists_the_bundle_and_the_inertia_version_follows_activation(): void
    {
        $this->actingAs($this->admin());

        $before = $this->version();

        $this->activateShowcase();

        $html = (string) $this->get($this->panelUrl('/admin/_probe'))->assertOk()->getContent();
        $hash = mb_substr(hash_file('sha256', self::showcasePackage() . '/dist/fapost-ui.json'), 0, 12);

        $this->assertStringContainsString('"Board/Index":"' . asset("solutions/showcase/{$hash}/assets/board-index-4c1d2a.js") . '"', $html);
        $this->assertStringContainsString('"css":["' . asset("solutions/showcase/{$hash}/assets/showcase-8f3d2c.css") . '"]', $html);

        // A tab still on the version from before the activation reloads in full.
        $this->get($this->panelUrl('/admin/_probe'), ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $before])
            ->assertStatus(Response::HTTP_CONFLICT);

        $after = $this->version();

        $this->assertNotSame($before, $after);
        $this->get($this->panelUrl('/admin/_probe'), ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $after])->assertOk();
    }

    public function test_a_column_is_filled_once_per_page_with_the_row_ids(): void
    {
        $this->activateShowcase();
        $values = new ShowcaseColumnValues();
        $this->app->instance(ShowcaseColumnValues::class, $values);
        $contacts = $this->contacts(2);
        $this->actingAs($this->admin());

        $rows = $this->props($this->get($this->contactsUrl()))['table']['rows'];

        $this->assertCount(1, $values->calls);
        $this->assertEqualsCanonicalizing(array_map(static fn (Contact $contact): string => (string) $contact->getKey(), $contacts), $values->calls[0]['ids']);
        $this->assertSame('score', $values->calls[0]['key']);
        $this->assertSame((string) $this->assistant->getKey(), $values->calls[0]['assistant']);

        foreach ($rows as $row) {
            $this->assertSame(mb_strlen($row['id']), $row['showcase.score']);
        }
    }

    public function test_a_failing_column_is_left_empty_and_the_table_still_renders(): void
    {
        $this->activateShowcase();
        $values       = new ShowcaseColumnValues();
        $values->fail = true;
        $this->app->instance(ShowcaseColumnValues::class, $values);
        $this->contacts(1);
        $this->actingAs($this->admin());

        $rows = $this->props($this->get($this->contactsUrl()))['table']['rows'];

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('showcase.score', $rows[0]);
        $this->assertNull($rows[0]['showcase.score']);
    }

    public function test_an_action_on_a_records_screen_links_to_that_record(): void
    {
        $this->activateShowcase();
        [$contact] = $this->contacts(1);
        $this->actingAs($this->admin());

        $actions = $this->props($this->get($this->contactsUrl('/' . $contact->getKey())))['extensions']['actions'];

        $this->assertSame("/assistant/{$this->assistant->getKey()}/apps/showcase/contacts/{$contact->getKey()}", $actions['contact.view'][0]['href']);
        $this->assertArrayNotHasKey('hrefTemplate', $actions['contact.view'][0]);
        $this->assertArrayHasKey('hrefTemplate', $actions['contact.row'][0]);
    }

    private function assistantQueries(callable $request): int
    {
        $count    = 0;
        $counting = true;
        DB::listen(static function (QueryExecuted $query) use (&$count, &$counting): void {
            if ($counting && 1 === preg_match('/from\s+"?assistants"?/i', $query->sql)) {
                $count++;
            }
        });

        $request();
        $counting = false;

        return $count;
    }

    /**
     * The Inertia version a first visit of the console gets.
     */
    private function version(): string
    {
        return (string) $this->get($this->panelUrl('/admin/_probe'))->assertOk()->viewData('page')['version'];
    }

    /**
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    /**
     * @return array<string, array<string, list<mixed>>>
     */
    private function emptyExtensions(): array
    {
        return [
            'shell'   => ['floating' => [], 'header' => []],
            'actions' => ['contact.view' => [], 'contact.row' => [], 'conversation.view' => []],
            'widgets' => ['console' => [], 'admin' => []],
            'tabs'    => ['assistant' => [], 'tenant' => []],
            'columns' => ['contacts' => [], 'conversations' => []],
        ];
    }

    private function contactsUrl(string $suffix = ''): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/contacts{$suffix}");
    }

    /**
     * Contacts that wrote to a channel of the assistant.
     *
     * @return list<Contact>
     */
    private function contacts(int $count): array
    {
        // Saving a channel reaches Redis and the provider through its observer; neither is wanted here.
        $this->app->instance(ChannelWebhookRegistryInterface::class, new class () implements ChannelWebhookRegistryInterface {
            public function set(Channel $channel, TenantInterface $tenant): void
            {
            }

            public function remove(string $webhookPublicHash): void
            {
            }

            public function warmup(TenantInterface $tenant): void
            {
            }
        });
        Model::clearBootedModels();
        Bus::fake([SyncChannelWebhookJob::class]);
        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());

        $channel  = Channel::factory()->create(['assistant_id' => $this->assistant->getKey()]);
        $contacts = [];

        for ($i = 0; $i < $count; $i++) {
            $contact = Contact::factory()->forTenant(self::TENANT_ID)->create();
            ChannelContact::query()->create(['contact_id' => $contact->getKey(), 'channel_id' => $channel->getKey()]);
            $contacts[] = $contact;
        }

        return $contacts;
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }
}
