<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Staff;

use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Notifications\StaffRecipientResolver;
use App\Domains\Staff\Services\PlatformSupportUserService;
use App\Filament\Widgets\StatsOverview;
use Database\Seeders\TenantAclSeeder;
use Livewire\Livewire;
use Tests\Feature\FeatureTestCase;

/**
 * The platform support user is not one of the tenant's people: it receives nothing and is not counted.
 */
final class PlatformSupportExclusionTest extends FeatureTestCase
{
    private User $admin;

    private User $support;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(RoleEnum::Admin->value);
        $this->support = $this->app->make(PlatformSupportUserService::class)->ensure();
    }

    public function test_staff_notifications_do_not_reach_the_support_user(): void
    {
        $resolver = $this->app->make(StaffRecipientResolver::class);

        $byRole = $resolver->resolve(['target' => 'role', 'role' => RoleEnum::Admin->value], null);
        $byIds  = $resolver->resolve(['target' => 'users', 'user_ids' => [$this->support->getKey(), $this->admin->getKey()]], null);

        $this->assertSame([$this->admin->getKey()], $byRole->modelKeys());
        $this->assertNotContains($this->support->getKey(), $byIds->modelKeys());
    }

    public function test_the_builder_staff_picker_omits_it(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/builder/staff');

        $response->assertOk();
        $this->assertSame([(string) $this->admin->getKey()], array_column($response->json('data'), 'value'));
    }

    public function test_the_dashboard_staff_count_omits_it(): void
    {
        $this->actingAs($this->admin);

        $component = Livewire::test(StatsOverview::class);
        $stats     = (fn (): array => $this->getStats())->call($component->instance());

        $staff = collect($stats)->first(fn ($stat): bool => __('staff.dashboard.stats.staff_users.label') === (string) $stat->getLabel());

        $this->assertNotNull($staff);
        $this->assertSame(1, (int) $staff->getValue());
    }
}
