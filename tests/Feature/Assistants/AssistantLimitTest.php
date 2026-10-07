<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Domains\Assistant\Contracts\AssistantServiceInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\Services\UnlimitedTenantLimits;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Filament\Resources\Assistants\AssistantResource;
use App\Filament\Resources\Assistants\Pages\CreateAssistant;
use App\Filament\Resources\Assistants\Pages\ListAssistants;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Filament\Facades\Filament;
use Livewire\Livewire;
use LogicException;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeTenantLimits;

/**
 * The assistant limit is checked in AssistantService::create() and surfaced in the admin panel.
 */
final class AssistantLimitTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_service_creates_until_the_limit_then_throws(): void
    {
        $this->limitAssistants(1);

        $this->inTenant(function (): void {
            $tenant  = $this->tenant();
            $service = app(AssistantServiceInterface::class);

            $service->create($tenant, ['name' => 'First']);

            try {
                $service->create($tenant, ['name' => 'Second']);
                $this->fail('Expected RecordLimitReachedException.');
            } catch (RecordLimitReachedException $e) {
                $this->assertSame('Assistants limit reached: 1 of 1.', $e->getMessage());
            }

            $this->assertSame(1, Assistant::query()->count());
        });
    }

    public function test_default_has_no_limit(): void
    {
        $this->app->instance(TenantLimitsInterface::class, new UnlimitedTenantLimits());

        $this->inTenant(function (): void {
            $tenant  = $this->tenant();
            $service = app(AssistantServiceInterface::class);

            $service->create($tenant, ['name' => 'A']);
            $service->create($tenant, ['name' => 'B']);

            $this->assertSame(2, Assistant::query()->count());
        });
    }

    public function test_create_refuses_a_tenant_other_than_the_current_context(): void
    {
        $this->inTenant(function (): void {
            $other = new RuntimeTenant('00000000-0000-0000-0000-0000000000ff', 'other');

            $this->expectException(LogicException::class);

            app(AssistantServiceInterface::class)->create($other, ['name' => 'Wrong']);
        });
    }

    public function test_limit_zero_refuses_the_first_assistant(): void
    {
        $this->limitAssistants(0);

        $this->inTenant(function (): void {
            $tenant = $this->tenant();
            $this->expectException(RecordLimitReachedException::class);

            app(AssistantServiceInterface::class)->create($tenant, ['name' => 'First']);
        });
    }

    public function test_downgrade_keeps_existing_assistants_but_forbids_new_ones(): void
    {
        $this->inTenant(function (): void {
            $tenant  = $this->tenant();
            $service = app(AssistantServiceInterface::class);
            $service->create($tenant, ['name' => 'A']);
            $service->create($tenant, ['name' => 'B']);

            $this->limitAssistants(1);
            $service = app(AssistantServiceInterface::class);

            try {
                $service->create($tenant, ['name' => 'C']);
                $this->fail('Expected RecordLimitReachedException.');
            } catch (RecordLimitReachedException) {
                $this->assertSame(2, Assistant::query()->count());
            }
        });
    }

    public function test_create_button_and_page_are_closed_for_admin_at_the_limit(): void
    {
        $this->actingAsPanelUser(admin: true);
        $this->limitAssistants(1);
        Assistant::factory()->create();

        $this->assertFalse(AssistantResource::canCreate());
        $this->get($this->panelUrl('/admin/assistants/create'))->assertForbidden();

        Livewire::test(ListAssistants::class)
            ->assertActionHidden('create')
            ->assertSee('Limit reached (1 of 1)');
    }

    public function test_create_button_is_closed_for_staff_with_permission_at_the_limit(): void
    {
        $this->actingAsPanelUser(admin: false);
        $this->limitAssistants(0);

        $this->assertFalse(AssistantResource::canCreate());
        $this->get($this->panelUrl('/admin/assistants/create'))->assertForbidden();
    }

    public function test_create_button_is_visible_below_the_limit(): void
    {
        $this->actingAsPanelUser(admin: true);
        $this->limitAssistants(2);
        Assistant::factory()->create();

        $this->assertTrue(AssistantResource::canCreate());

        Livewire::test(ListAssistants::class)
            ->assertActionVisible('create')
            ->assertDontSee('Limit reached');
    }

    public function test_race_shows_a_notification_instead_of_an_error(): void
    {
        $this->actingAsPanelUser(admin: true);
        $limits = $this->limitAssistants(1);

        $component = Livewire::test(CreateAssistant::class)
            ->fillForm(['name' => 'Raced', 'default_language' => 'en']);

        // Another request takes the last slot after this page's own access checks have passed:
        // Filament checks canCreate() when the component hydrates and again inside create().
        Assistant::factory()->create();
        $limits->unlimitedAnswers = 2;

        $component->call('create')->assertNotified(__('staff.assistants.limit.reached_title'));

        $this->assertSame(1, Assistant::query()->count());
    }

    private function limitAssistants(?int $limit): FakeTenantLimits
    {
        $limits = new FakeTenantLimits(['assistants' => $limit]);

        $this->app->instance(TenantLimitsInterface::class, $limits);

        return $limits;
    }

    private function actingAsPanelUser(bool $admin): void
    {
        $this->app->make(TenantContextInterface::class)->set($this->tenant());

        $user = User::factory()->create();
        $admin ? $user->assignRole(RoleEnum::Admin->value) : $user->givePermissionTo(Permission::ManageAssistants->value);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->firstOrFail();
    }

    /**
     * @param  callable(): void  $callback
     */
    private function inTenant(callable $callback): void
    {
        $this->app->make(TenantSwitcher::class)->runForTenant($this->tenant(), $callback);
    }
}
