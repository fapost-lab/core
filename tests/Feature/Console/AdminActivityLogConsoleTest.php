<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Enums\AuditActorKind;
use App\Domains\Audit\Enums\AuditArea;
use App\Domains\Audit\Models\ActivityEntry;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;

/**
 * The tenant's activity log in the admin panel: read only, newest first inside a 30-day window unless another period
 * is chosen, filterable, with the recorded values in the row, behind its own permission, and free of secrets.
 */
final class AdminActivityLogConsoleTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        CarbonImmutable::setTestNow('2026-11-11 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_log_lists_entries_newest_first_inside_the_default_window(): void
    {
        $this->entry(['subject_label' => 'Older', 'created_at' => '2026-11-01 09:00:00']);
        $this->entry(['subject_label' => 'Newest', 'created_at' => '2026-11-11 11:00:00']);
        $this->entry(['subject_label' => 'Ancient', 'created_at' => '2026-08-01 09:00:00']);

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/ActivityLog/Index')
                ->where('navigation.mode', 'admin')
                ->where('urls.index', '/admin/activity')
                ->where('urls.supportAccess', '/admin/support-access')
                ->where('table.defaults.sort', '-created_at')
                ->where('table.state.filters.period', '30')
                ->where('filters.periods', ['7', '30', '90', 'all'])
                ->where('table.meta.total', 2)
                ->where('table.rows.0.subjectLabel', 'Newest')
                ->where('table.rows.0.createdAt', CarbonImmutable::parse('2026-11-11 11:00:00')->toIso8601String())
                ->where('table.rows.1.subjectLabel', 'Older')
                ->etc());
    }

    public function test_the_period_filter_widens_or_narrows_the_window(): void
    {
        $this->entry(['subject_label' => 'Today', 'created_at' => '2026-11-11 08:00:00']);
        $this->entry(['subject_label' => 'Last month', 'created_at' => '2026-10-20 08:00:00']);
        $this->entry(['subject_label' => 'Ancient', 'created_at' => '2026-05-01 08:00:00']);

        $this->actingAs($this->admin());

        foreach (['7' => ['Today'], '30' => ['Today', 'Last month'], 'all' => ['Today', 'Last month', 'Ancient'], 'bogus' => ['Today', 'Last month']] as $period => $expected) {
            $this->get($this->url('?filter[period]=' . $period))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('table.rows', fn ($rows): bool => $expected === collect($rows)->pluck('subjectLabel')->all())
                    ->etc());
        }

        $this->get($this->url('?filter[period]=all'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.state.filters.period', 'all')->etc());
    }

    public function test_the_search_matches_the_actor_and_the_subject_label(): void
    {
        $this->entry(['actor_label' => 'Anna Admin', 'subject_label' => 'Welcome flow']);
        $this->entry(['actor_label' => 'Boris Editor', 'subject_label' => 'Spring broadcast']);

        $this->actingAs($this->admin());

        foreach (['anna' => ['Welcome flow'], 'spring' => ['Spring broadcast'], 'nobody' => []] as $search => $expected) {
            $this->get($this->url('?search=' . urlencode($search)))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('table.rows', fn ($rows): bool => $expected === collect($rows)->pluck('subjectLabel')->all())
                    ->etc());
        }
    }

    public function test_each_filter_narrows_the_list(): void
    {
        $anna   = User::factory()->create(['name' => 'Anna']);
        $helper = Assistant::factory()->create(['name' => 'Helper']);
        $other  = Assistant::factory()->create(['name' => 'Other']);

        $this->entry(['subject_label' => 'staff flow', 'log_name' => 'flows', 'causer_id' => (string) $anna->getKey(), 'assistant_id' => (string) $helper->getKey()]);
        $this->entry(['subject_label' => 'staff channel', 'log_name' => 'channels', 'actor_kind' => AuditActorKind::Staff, 'causer_id' => (string) User::factory()->create()->getKey(), 'assistant_id' => (string) $other->getKey()]);
        $this->entry(['subject_label' => 'support run', 'log_name' => 'settings', 'actor_kind' => AuditActorKind::Support]);
        $this->entry(['subject_label' => 'system run', 'log_name' => 'settings', 'actor_kind' => AuditActorKind::System, 'actor_label' => null]);

        $this->actingAs($this->admin());

        $cases = [
            'filter[area]=flows'                          => ['staff flow'],
            'filter[area]=nonsense'                       => ['system run', 'support run', 'staff channel', 'staff flow'],
            'filter[actor]=support'                       => ['support run'],
            'filter[actor]=system'                        => ['system run'],
            'filter[actor]=' . $anna->getKey()            => ['staff flow'],
            'filter[assistant]=' . $helper->getKey()      => ['staff flow'],
            'filter[area]=settings&filter[actor]=support' => ['support run'],
        ];

        foreach ($cases as $query => $expected) {
            $this->get($this->url('?' . $query))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('table.rows', fn ($rows): bool => $this->sameSet($expected, collect($rows)->pluck('subjectLabel')->all()))
                    ->etc());
        }
    }

    public function test_the_filter_options_are_the_areas_the_staff_the_assistants_and_never_the_support_user(): void
    {
        $anna = User::factory()->create(['name' => 'Anna']);
        User::factory()->create(['name' => 'Platform support', 'is_platform_support' => true]);
        Assistant::factory()->create(['name' => 'Helper']);

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.areas', fn ($areas): bool => AuditArea::values() === collect($areas)->pluck('key')->all()
                    && 'Assistants' === $areas[0]['label'])
                ->where('filters.actors', fn ($actors): bool => collect($actors)->pluck('name')->contains('Anna')
                    && ! collect($actors)->pluck('name')->contains('Platform support')
                    && collect($actors)->pluck('id')->contains((string) $anna->getKey()))
                ->where('filters.assistants', fn ($assistants): bool => ['Helper'] === collect($assistants)->pluck('name')->all())
                ->etc());
    }

    public function test_a_row_carries_the_labels_the_values_the_markers_and_the_properties(): void
    {
        $assistant = Assistant::factory()->create(['name' => 'Helper']);

        $this->entry([
            'log_name'          => 'assistants',
            'description'       => AuditAction::AssistantUpdated->value,
            'actor_label'       => 'Anna',
            'subject_label'     => 'Helper',
            'assistant_id'      => (string) $assistant->getKey(),
            'attribute_changes' => ['attributes' => ['name' => 'Helper', 'is_active' => false], 'old' => ['name' => 'Old', 'is_active' => true], 'changed' => ['description']],
            'properties'        => ['reason' => 'cleanup', 'attempts' => 3],
        ]);

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows.0.actorKind', 'staff')
                ->where('table.rows.0.actorLabel', 'Anna')
                ->where('table.rows.0.action', 'assistant.updated')
                ->where('table.rows.0.actionLabel', 'Assistant updated')
                ->where('table.rows.0.area', 'assistants')
                ->where('table.rows.0.areaLabel', 'Assistants')
                ->where('table.rows.0.subjectLabel', 'Helper')
                ->where('table.rows.0.assistantName', 'Helper')
                ->where('table.rows.0.values', [
                    ['field' => 'name', 'old' => 'Old', 'new' => 'Helper'],
                    ['field' => 'is_active', 'old' => 'true', 'new' => 'false'],
                ])
                ->where('table.rows.0.markers', ['description'])
                ->where('table.rows.0.properties', [['key' => 'reason', 'value' => 'cleanup'], ['key' => 'attempts', 'value' => '3']])
                ->where('table.rows.0.supportEntryId', null)
                ->etc());
    }

    public function test_a_support_row_names_its_support_entry(): void
    {
        $this->entry([
            'actor_kind'  => AuditActorKind::Support,
            'actor_label' => 'Platform support (Olga)',
            'properties'  => ['support_entry_id' => 'entry-42', 'operator_name' => 'Olga'],
        ]);

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows.0.actorKind', 'support')
                ->where('table.rows.0.supportEntryId', 'entry-42')
                ->where('table.rows.0.properties', [['key' => 'operator_name', 'value' => 'Olga']])
                ->etc());
    }

    public function test_an_action_without_a_translation_shows_its_key(): void
    {
        $this->entry(['description' => 'future.thing_happened', 'log_name' => 'future']);

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows.0.actionLabel', 'future.thing_happened')
                ->where('table.rows.0.areaLabel', 'future')
                ->etc());
    }

    public function test_no_secret_reaches_the_page(): void
    {
        $this->entry([
            'attribute_changes' => ['attributes' => ['bot_token' => 'LEAKED-7f3', 'name' => 'Visible'], 'old' => ['bot_token' => 'LEAKED-7f3', 'name' => 'Before']],
            'properties'        => ['secret_token' => 'LEAKED-7f3', 'webhook_public_hash' => 'LEAKED-7f3', 'password' => 'LEAKED-7f3', 'kept' => 'yes'],
        ]);

        $response = $this->actingAs($this->admin())->get($this->url());

        $response->assertOk()
            ->assertDontSee('LEAKED-7f3')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows.0.values', [['field' => 'name', 'old' => 'Before', 'new' => 'Visible']])
                ->where('table.rows.0.properties', [['key' => 'kept', 'value' => 'yes']])
                ->etc());
    }

    public function test_the_admin_menu_links_to_the_log_inside_the_shell(): void
    {
        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('navigation.groups', fn ($groups): bool => collect($groups)
                    ->flatMap(static fn (array $group): array => $group['items'])
                    ->contains(static fn (array $item): bool => 'console.admin.activity.index' === $item['key']
                        && '/admin/activity' === $item['href']
                        && false === $item['external']))
                ->etc());
    }

    public function test_only_the_right_to_view_the_log_opens_it_and_shows_its_menu_item(): void
    {
        $allowed = User::factory()->create();
        $allowed->givePermissionTo(Permission::ViewActivityLog->value);

        $this->actingAs($allowed)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('navigation.groups', fn ($groups): bool => $this->menuHasLog($groups))->etc());

        $denied = User::factory()->create();
        $denied->givePermissionTo(Permission::ViewSystem->value);

        $this->actingAs($denied)->get($this->url())->assertForbidden();
        $this->actingAs($denied)
            ->get($this->panelUrl('/admin/health'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('navigation.groups', fn ($groups): bool => ! $this->menuHasLog($groups))->etc());
    }

    public function test_a_visitor_is_sent_to_the_sign_in(): void
    {
        $this->get($this->url())->assertRedirect(route('console.auth.login'));
    }

    public function test_every_audit_key_is_translated_in_every_language(): void
    {
        foreach (['en', 'ru', 'uk'] as $locale) {
            $audit = require lang_path("{$locale}/audit.php");

            foreach (AuditArea::values() as $area) {
                $this->assertNotSame('', Arr::get($audit, "areas.{$area}", ''), "audit.areas.{$area} in {$locale}");
            }

            foreach (AuditAction::cases() as $action) {
                $this->assertNotSame('', Arr::get($audit['actions'], $action->value, ''), "audit.actions.{$action->value} in {$locale}");
            }

            $this->assertSame(count(AuditAction::cases()), count(Arr::dot($audit['actions'])), "no stray action keys in {$locale}");
            $this->assertSame(count(AuditArea::cases()), count($audit['areas']), "no stray area keys in {$locale}");
        }

        $keys = static fn (string $locale): array => array_keys(Arr::dot((array) Arr::get(require lang_path("{$locale}/console.php"), 'activity_log')));

        foreach (['ru', 'uk'] as $locale) {
            $this->assertEqualsCanonicalizing($keys('en'), $keys($locale), "console.activity_log in {$locale}");
        }

    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function entry(array $values = []): ActivityEntry
    {
        $values = [
            'log_name'      => 'flows',
            'description'   => AuditAction::FlowUpdated->value,
            'event'         => 'updated',
            'actor_kind'    => AuditActorKind::Staff,
            'actor_label'   => 'Someone',
            'subject_label' => 'Subject',
            'created_at'    => '2026-11-10 10:00:00',
            ...$values,
        ];
        $values['created_at'] = CarbonImmutable::parse($values['created_at']);

        return ActivityEntry::query()->create($values);
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>  $actual
     */
    private function sameSet(array $expected, array $actual): bool
    {
        sort($expected);
        sort($actual);

        return $expected === $actual;
    }

    /**
     * @param  iterable<int, array{items: list<array{key: string}>}>  $groups
     */
    private function menuHasLog(iterable $groups): bool
    {
        return collect($groups)
            ->flatMap(static fn (array $group): array => $group['items'])
            ->contains(static fn (array $item): bool => 'console.admin.activity.index' === $item['key']);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    private function url(string $suffix = ''): string
    {
        return $this->panelUrl("/admin/activity{$suffix}");
    }
}
