<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\PlatformSupportUserService;
use App\Http\Shell\UsagePresenter;
use Carbon\CarbonImmutable;
use Database\Seeders\TenantAclSeeder;
use DateTimeImmutable;
use Fapost\Foundation\Quota\Contracts\PeriodUsageInterface;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Fapost\Foundation\Quota\DTO\PeriodUsage;
use Fapost\Foundation\Quota\DTO\PeriodUsageReport;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakePeriodUsage;
use Tests\Support\FakeTenantLimits;

/**
 * The usage and limits page of the admin panel, and the dashboard's compact card: Core's own counts for records and
 * stored bytes, the operator's report for per-period use, and the rights and the fallbacks around them.
 */
final class AdminUsageConsoleTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private FakeTenantLimits $limits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        CarbonImmutable::setTestNow('2026-10-16 12:00:00');

        $this->limits = new FakeTenantLimits();
        $this->app->instance(TenantLimitsInterface::class, $this->limits);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_records_and_storage_show_the_count_enforcement_uses(): void
    {
        $this->limits->limits = ['assistants' => 5, 'staff' => 2, 'media_storage' => 4096];
        Assistant::factory()->count(2)->create();
        $admin = $this->admin();
        User::factory()->create(['is_active' => false]);
        $this->app->make(PlatformSupportUserService::class)->ensure();
        $this->blob('a', 1024);

        $this->actingAs($admin)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/AdminUsage/Index')
                ->where('navigation.mode', 'admin')
                ->where('limited', true)
                ->where('action', null)
                ->where('rows', fn ($rows): bool => $this->assertRow($rows, 'assistants', used: 2, limit: 5, state: 'ok', percent: 40, usedText: '2', limitText: '5', label: 'Assistants'))
                // The deactivated account and the platform support user are not counted, as when staff are created.
                ->where('rows', fn ($rows): bool => $this->assertRow($rows, 'staff', used: 1, limit: 2, state: 'ok', percent: 50, usedText: '1', limitText: '2', label: 'Staff accounts'))
                ->where('rows', fn ($rows): bool => $this->assertRow($rows, 'media_storage', used: 1024, limit: 4096, state: 'ok', percent: 25, usedText: '1 KB', limitText: '4 KB', label: 'Media storage'))
                ->where('rows', fn ($rows): bool => $this->assertRow($rows, 'flows', used: 0, limit: null, state: 'unlimited', percent: null, usedText: '0', limitText: null, label: 'Flows'))
                ->etc());
    }

    public function test_per_period_rows_come_from_the_operator_with_the_period_and_the_button(): void
    {
        $this->limits->limits = ['monthly_active_contacts' => 999, 'outbound_messages' => 500];
        $operator             = new FakePeriodUsage(new PeriodUsageReport(
            [
                new PeriodUsage('monthly_active_contacts', 450, 500, new DateTimeImmutable('2026-10-12'), new DateTimeImmutable('2026-11-12')),
                new PeriodUsage('call_executions', 12, null, new DateTimeImmutable('2026-10-12'), new DateTimeImmutable('2026-11-12')),
            ],
            'Upgrade plan',
            'https://billing.example.test/upgrade',
        ));
        $this->app->instance(PeriodUsageInterface::class, $operator);

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('action', ['label' => 'Upgrade plan', 'url' => 'https://billing.example.test/upgrade'])
                ->where('limited', true)
                // The limit is the one in the report, not the cached answer of the limits contract.
                ->where('rows', fn ($rows): bool => $this->assertRow($rows, 'monthly_active_contacts', used: 450, limit: 500, state: 'near', percent: 90, usedText: '450', limitText: '500', label: 'Monthly active contacts'))
                ->where('rows', fn ($rows): bool => $this->assertRow($rows, 'call_executions', used: 12, limit: null, state: 'unlimited', percent: null, usedText: '12', limitText: null, label: 'Call executions'))
                // The operator did not report this key, but it has a limit: "not reported", never zero.
                ->where('rows', fn ($rows): bool => $this->assertRow($rows, 'outbound_messages', used: null, limit: 500, state: 'not_reported', percent: null, usedText: null, limitText: '500', label: 'Outbound messages'))
                ->where('rows', fn ($rows): bool => 'November 12, 2026' === $this->rowByKey($rows, 'monthly_active_contacts')['periodEndsLabel'])
                ->etc());

        $this->assertCount(1, $operator->calls);
        $this->assertSame(self::TENANT_ID, $operator->calls[0]['tenantId']);
    }

    public function test_the_period_end_is_written_in_the_readers_language(): void
    {
        $this->app->instance(PeriodUsageInterface::class, new FakePeriodUsage(new PeriodUsageReport(
            [new PeriodUsage('call_executions', 1, 10, new DateTimeImmutable('2026-10-12'), new DateTimeImmutable('2026-11-12'))],
        )));

        $this->actingAs($this->admin())
            ->get($this->url('?lang=ru'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows', fn ($rows): bool => '12 ноября 2026 г.' === $this->rowByKey($rows, 'call_executions')['periodEndsLabel']
                    && 'Выполнения вызовов' === $this->rowByKey($rows, 'call_executions')['label'])
                ->where('translations.console.usage.title', 'Использование и лимиты')
                ->etc());
    }

    public function test_without_an_operator_the_page_shows_core_counts_as_unlimited(): void
    {
        $this->app->forgetInstance(TenantLimitsInterface::class);
        $this->app->bind(TenantLimitsInterface::class, \App\Domains\Tenancy\Services\UnlimitedTenantLimits::class);
        Assistant::factory()->create();

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('limited', false)
                ->where('action', null)
                ->where('rows', fn ($rows): bool => 'unlimited' === $this->rowByKey($rows, 'assistants')['state']
                    && 1 === $this->rowByKey($rows, 'assistants')['used']
                    && 0 === collect($rows)->where('kind', 'per_period')->count()
                    && 'unlimited' === $this->rowByKey($rows, 'media_storage')['state'])
                ->etc());
    }

    public function test_a_failing_operator_does_not_break_the_page_and_is_reported(): void
    {
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (RuntimeException $e) use (&$reported): bool {
            $reported[] = $e->getMessage();

            return false;
        });
        $this->limits->limits = ['assistants' => 5];
        $this->app->instance(PeriodUsageInterface::class, new FakePeriodUsage(failure: new RuntimeException('billing is down')));

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('limited', true)
                ->where('rows', fn ($rows): bool => 'ok' === $this->rowByKey($rows, 'assistants')['state']
                    && 'unavailable' === $this->rowByKey($rows, 'call_executions')['state']
                    && null === $this->rowByKey($rows, 'call_executions')['limit'])
                ->etc());

        $this->assertSame(['billing is down'], $reported);
    }

    public function test_a_limit_that_cannot_be_read_is_unavailable_and_not_unlimited(): void
    {
        $this->app->make(ExceptionHandler::class)->reportable(static fn (RuntimeException $e): bool => false);
        $this->limits->failure = new RuntimeException('limits are down');

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('limited', true)
                ->where('rows', fn ($rows): bool => 'unavailable' === $this->rowByKey($rows, 'assistants')['state'])
                ->etc());
    }

    public function test_only_the_right_to_manage_settings_opens_the_page_and_shows_its_menu_item(): void
    {
        $allowed = User::factory()->create();
        $allowed->givePermissionTo(Permission::ManageSettings->value);
        $this->actingAs($allowed)->get($this->url())->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('navigation.groups', fn ($groups): bool => $this->hasUsageItem($groups))->etc());

        $denied = User::factory()->create();
        $denied->givePermissionTo(Permission::ViewSystem->value);
        $this->actingAs($denied)->get($this->url())->assertForbidden();
        $this->actingAs($denied)->get($this->panelUrl('/admin'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('navigation.groups', fn ($groups): bool => ! $this->hasUsageItem($groups))->etc());
    }

    public function test_a_visitor_is_sent_to_the_sign_in(): void
    {
        $this->get($this->url())->assertRedirect(route('console.auth.login'));
    }

    public function test_the_dashboard_card_lists_the_fullest_limits_first_and_asks_the_operator_once(): void
    {
        $this->limits->limits = ['assistants' => 10, 'flows' => 10, 'channels' => 10, 'staff' => 10, 'media_storage' => 10, 'call_executions' => 100, 'outbound_messages' => 100, 'monthly_active_contacts' => 100];
        Assistant::factory()->count(2)->create();
        $operator = new FakePeriodUsage(new PeriodUsageReport([
            new PeriodUsage('call_executions', 100, 100, new DateTimeImmutable('2026-10-12'), new DateTimeImmutable('2026-11-12')),
            new PeriodUsage('outbound_messages', 85, 100, new DateTimeImmutable('2026-10-12'), new DateTimeImmutable('2026-11-12')),
        ], 'Upgrade plan', 'https://billing.example.test/upgrade'));
        $this->app->instance(PeriodUsageInterface::class, $operator);

        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('usage.url', '/admin/usage')
                ->where('usage.limited', true)
                ->where('usage.total', 8)
                ->where('usage.action.label', 'Upgrade plan')
                ->has('usage.rows', UsagePresenter::WIDGET_ROWS)
                // The reached limit comes first, then the one that is almost used up, then the rest by share.
                ->where('usage.rows.0.key', 'call_executions')
                ->where('usage.rows.0.state', 'reached')
                ->where('usage.rows.1.key', 'outbound_messages')
                ->where('usage.rows.1.state', 'near')
                ->where('usage.rows.2.key', 'assistants')
                ->etc());

        $this->assertCount(1, $operator->calls);
    }

    public function test_the_dashboard_card_is_not_part_of_the_polling_refresh(): void
    {
        $operator = new FakePeriodUsage();
        $this->app->instance(PeriodUsageInterface::class, $operator);
        $this->limits->limits = ['call_executions' => 5];

        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin'), ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Console/AdminDashboard/Index', 'X-Inertia-Partial-Data' => 'stats,activity'])
            ->assertOk();

        $this->assertSame([], $operator->calls);
    }

    public function test_the_dashboard_card_says_figures_are_unavailable_when_the_operator_fails(): void
    {
        $this->app->make(ExceptionHandler::class)->reportable(static fn (RuntimeException $e): bool => false);
        $this->app->bind(TenantLimitsInterface::class, \App\Domains\Tenancy\Services\UnlimitedTenantLimits::class);
        $this->app->forgetInstance(TenantLimitsInterface::class);
        $this->app->instance(PeriodUsageInterface::class, new FakePeriodUsage(failure: new RuntimeException('billing is down')));

        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('usage.limited', true)
                ->where('usage.rows', [])
                ->where('usage.unavailable', 3)
                ->etc());
    }

    public function test_the_dashboard_card_says_no_limits_are_set_on_a_plain_installation(): void
    {
        $this->app->bind(TenantLimitsInterface::class, \App\Domains\Tenancy\Services\UnlimitedTenantLimits::class);
        $this->app->forgetInstance(TenantLimitsInterface::class);

        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('usage.limited', false)
                ->where('usage.rows', [])
                ->etc());
    }

    public function test_the_dashboard_card_is_only_for_who_may_open_the_usage_page(): void
    {
        $operator = new FakePeriodUsage();
        $this->app->instance(PeriodUsageInterface::class, $operator);
        $this->limits->limits = ['call_executions' => 5];
        $user                 = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);

        $this->actingAs($user)
            ->get($this->panelUrl('/admin'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('usage', null)->etc());

        $this->assertSame([], $operator->calls);
    }

    public function test_every_language_has_the_usage_strings(): void
    {
        $keys = static fn (string $locale, string $file, string $path): array => array_keys(Arr::dot((array) Arr::get(require lang_path("{$locale}/{$file}.php"), $path)));

        foreach (['ru', 'uk'] as $locale) {
            $this->assertEqualsCanonicalizing($keys('en', 'console', 'usage'), $keys($locale, 'console', 'usage'), "console.usage in {$locale}");
            $this->assertEqualsCanonicalizing($keys('en', 'console', 'admin_dashboard.usage'), $keys($locale, 'console', 'admin_dashboard.usage'), "admin_dashboard.usage in {$locale}");
            $this->assertArrayHasKey('view_usage', require lang_path("{$locale}/limits.php"));
        }
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    private function assertRow(iterable $rows, string $key, ?int $used, ?int $limit, string $state, ?int $percent, ?string $usedText, ?string $limitText, string $label): bool
    {
        $row = $this->rowByKey($rows, $key);

        $this->assertEquals(
            ['used' => $used, 'limit' => $limit, 'state' => $state, 'percent' => $percent, 'usedText' => $usedText, 'limitText' => $limitText, 'label' => $label],
            Arr::only($row, ['used', 'limit', 'state', 'percent', 'usedText', 'limitText', 'label']),
            "row {$key}",
        );

        return true;
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function rowByKey(iterable $rows, string $key): array
    {
        foreach ($rows as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        $this->fail("No row {$key}.");
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $groups
     */
    private function hasUsageItem(iterable $groups): bool
    {
        return collect($groups)
            ->flatMap(static fn (array $group): array => $group['items'])
            ->contains(static fn (array $item): bool => 'console.admin.usage.index' === $item['key'] && '/admin/usage' === $item['href']);
    }

    private function blob(string $seed, int $size): void
    {
        MediaBlob::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'content_hash' => hash('sha256', $seed),
            'storage_path' => 'tenants/' . self::TENANT_ID . '/media/' . $seed . '.bin',
            'storage_disk' => 'local',
            'size'         => $size,
            'mime_type'    => 'application/octet-stream',
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    private function url(string $suffix = ''): string
    {
        return $this->panelUrl("/admin/usage{$suffix}");
    }
}
