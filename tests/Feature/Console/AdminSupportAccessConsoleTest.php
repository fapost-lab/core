<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\SupportAccessEntry;
use App\Domains\Staff\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\TenantAclSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * The tenant's support access log in the admin panel: read only, newest entry first, behind the right that manages the
 * staff users, and linked from the admin menu inside the shell.
 */
final class AdminSupportAccessConsoleTest extends InertiaConsoleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        CarbonImmutable::setTestNow('2026-10-07 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_log_lists_entries_newest_first_with_their_session_state(): void
    {
        $this->entry('Old Operator', 'old@platform.test', '10.0.0.1', '2026-10-01 09:00:00', '2026-10-01 09:20:00');
        $this->entry('Live Operator', 'live@platform.test', '10.0.0.2', '2026-10-07 11:30:00', null);
        // Left open past the session's hour: abandoned, not in progress.
        $this->entry('Gone Operator', 'gone@platform.test', null, '2026-10-05 08:00:00', null);

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/SupportAccess/Index')
                ->where('navigation.mode', 'admin')
                ->where('urls.index', '/admin/support-access')
                ->where('table.defaults.sort', '-entered_at')
                ->where('table.meta.total', 3)
                ->where('table.rows.0.operatorName', 'Live Operator')
                ->where('table.rows.0.operatorEmail', 'live@platform.test')
                ->where('table.rows.0.ip', '10.0.0.2')
                ->where('table.rows.0.enteredAt', CarbonImmutable::parse('2026-10-07 11:30:00')->toIso8601String())
                ->where('table.rows.0.leftAt', null)
                ->where('table.rows.0.isOpen', true)
                ->where('table.rows.1.operatorName', 'Gone Operator')
                ->where('table.rows.1.ip', null)
                ->where('table.rows.1.isOpen', false)
                ->where('table.rows.2.operatorName', 'Old Operator')
                ->where('table.rows.2.leftAt', CarbonImmutable::parse('2026-10-01 09:20:00')->toIso8601String())
                ->where('table.rows.2.isOpen', false)
                ->etc());
    }

    public function test_the_search_matches_the_operator_email_and_ip(): void
    {
        $this->entry('Ann Support', 'ann@platform.test', '10.0.0.1', '2026-10-01 09:00:00', '2026-10-01 09:20:00');
        $this->entry('Bob Support', 'bob@platform.test', '192.168.1.5', '2026-10-02 09:00:00', '2026-10-02 09:20:00');

        $this->actingAs($this->admin());

        foreach (['ann@' => 'Ann Support', '192.168' => 'Bob Support', 'nobody' => null] as $search => $expected) {
            $this->get($this->url('?search=' . urlencode($search)))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('table.rows', fn ($rows): bool => null === $expected
                        ? 0 === count($rows)
                        : [$expected] === collect($rows)->pluck('operatorName')->all())
                    ->etc());
        }
    }

    public function test_the_admin_menu_links_to_the_log_inside_the_shell(): void
    {
        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('navigation.groups', fn ($groups): bool => collect($groups)
                    ->flatMap(static fn (array $group): array => $group['items'])
                    ->contains(static fn (array $item): bool => 'console.admin.support-access.index' === $item['key']
                        && '/admin/support-access' === $item['href']
                        && false === $item['external']))
                ->etc());
    }

    public function test_only_the_right_to_manage_users_opens_the_log(): void
    {
        $allowed = User::factory()->create();
        $allowed->givePermissionTo(Permission::ManageUsers->value);
        $this->actingAs($allowed)->get($this->url())->assertOk();

        $denied = User::factory()->create();
        $denied->givePermissionTo(Permission::ViewSystem->value);
        $this->actingAs($denied)->get($this->url())->assertForbidden();
    }

    public function test_a_visitor_is_sent_to_the_sign_in(): void
    {
        $this->get($this->url())->assertRedirect(route('filament.admin.auth.login'));
    }

    private function entry(string $name, string $email, ?string $ip, string $enteredAt, ?string $leftAt): void
    {
        SupportAccessEntry::query()->create([
            'operator_ref'   => 'op-' . $email,
            'operator_name'  => $name,
            'operator_email' => $email,
            'ip'             => $ip,
            'entered_at'     => CarbonImmutable::parse($enteredAt),
            'left_at'        => null === $leftAt ? null : CarbonImmutable::parse($leftAt),
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
        return $this->panelUrl("/admin/support-access{$suffix}");
    }
}
