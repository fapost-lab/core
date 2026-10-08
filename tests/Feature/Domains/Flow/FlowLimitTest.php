<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Actions\CreateFlowAction;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Tenancy\Services\UnlimitedTenantLimits;
use App\Filament\Assistant\Pages\AssistantSettings;
use App\Filament\Assistant\Resources\Flows\FlowResource;
use App\Filament\Assistant\Resources\Flows\Pages\CreateFlow;
use App\Filament\Assistant\Resources\Flows\Pages\ListFlows;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Concerns\SetsRecordLimits;
use Tests\Feature\FeatureTestCase;

/**
 * The flow limit counts drafts tenant-wide and is checked in CreateFlowAction, the only place a
 * flow is created; the assistant panel closes its create controls at the limit.
 */
final class FlowLimitTest extends FeatureTestCase
{
    use SetsRecordLimits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_action_creates_until_the_limit_then_throws(): void
    {
        $this->limitRecords('flows', 1);
        $assistant = Assistant::factory()->create();

        $this->inTenant(function () use ($assistant): void {
            $action = app(CreateFlowAction::class);

            $action->execute(['assistant_id' => (string) $assistant->getKey(), 'name' => 'First']);

            try {
                $action->execute(['assistant_id' => (string) $assistant->getKey(), 'name' => 'Second']);
                $this->fail('Expected RecordLimitReachedException.');
            } catch (RecordLimitReachedException $e) {
                $this->assertSame('Flows limit reached: 1 of 1.', $e->getMessage());
            }

            $this->assertSame(1, FlowDraft::query()->count());
        });
    }

    public function test_default_has_no_limit(): void
    {
        $this->app->instance(TenantLimitsInterface::class, new UnlimitedTenantLimits());
        $assistant = Assistant::factory()->create();

        $this->inTenant(function () use ($assistant): void {
            $action = app(CreateFlowAction::class);

            $action->execute(['assistant_id' => (string) $assistant->getKey(), 'name' => 'A']);
            $action->execute(['assistant_id' => (string) $assistant->getKey(), 'name' => 'B']);

            $this->assertSame(2, FlowDraft::query()->count());
        });
    }

    public function test_limit_zero_refuses_the_first_flow(): void
    {
        $this->limitRecords('flows', 0);
        $assistant = Assistant::factory()->create();

        $this->inTenant(function () use ($assistant): void {
            $this->expectException(RecordLimitReachedException::class);

            app(CreateFlowAction::class)->execute(['assistant_id' => (string) $assistant->getKey(), 'name' => 'First']);
        });
    }

    public function test_downgrade_keeps_existing_flows_but_forbids_new_ones(): void
    {
        $assistant = Assistant::factory()->create();
        FlowDraft::factory()->count(2)->create(['assistant_id' => $assistant->getKey()]);
        $this->limitRecords('flows', 1);

        $this->inTenant(function () use ($assistant): void {
            try {
                app(CreateFlowAction::class)->execute(['assistant_id' => (string) $assistant->getKey(), 'name' => 'C']);
                $this->fail('Expected RecordLimitReachedException.');
            } catch (RecordLimitReachedException) {
                $this->assertSame(2, FlowDraft::query()->count());
            }
        });
    }

    public function test_count_is_tenant_wide_from_inside_the_assistant_panel(): void
    {
        $first  = Assistant::factory()->create();
        $second = Assistant::factory()->create();
        FlowDraft::factory()->create(['assistant_id' => $first->getKey()]);
        FlowDraft::factory()->create(['assistant_id' => $second->getKey()]);
        $this->limitRecords('flows', 2);

        $this->actingAsAdmin('assistant');
        // A request to the panel registers its tenancy scope on the model, as in production.
        $this->get(route('filament.assistant.pages.dashboard', ['tenant' => $first]))->assertOk();
        Filament::setCurrentPanel(Filament::getPanel('assistant'));
        Filament::setTenant($first);

        $this->assertSame(1, FlowDraft::query()->count(), 'The panel scopes plain queries to the current assistant.');
        $this->assertSame(2, FlowDraft::countForLimit());
        $this->assertTrue(FlowResource::isLimitReached());
        $this->assertFalse(FlowResource::canCreate());

        $this->expectException(RecordLimitReachedException::class);
        app(CreateFlowAction::class)->execute(['assistant_id' => (string) $first->getKey(), 'name' => 'Third']);
    }

    public function test_create_button_and_page_are_closed_for_admin_at_the_limit(): void
    {
        $assistant = Assistant::factory()->create();
        FlowDraft::factory()->create(['assistant_id' => $assistant->getKey()]);
        $this->limitRecords('flows', 1);
        $this->actingAsAdmin('assistant');
        Filament::setTenant($assistant);

        $this->assertFalse(FlowResource::canCreate());
        $this->get(FlowResource::getUrl('create', tenant: $assistant))->assertForbidden();

        Livewire::test(ListFlows::class)
            ->assertActionHidden('create')
            ->assertSee('Limit reached (1 of 1)');
    }

    public function test_create_button_is_closed_for_staff_with_permission_at_the_limit(): void
    {
        $assistant = Assistant::factory()->create();
        $this->limitRecords('flows', 0);
        $this->actingAsStaff('assistant', Permission::ManageAssistants, Permission::ManageFlowDefinitions);
        Filament::setTenant($assistant);

        $this->assertFalse(FlowResource::canCreate());
    }

    public function test_create_button_is_visible_below_the_limit(): void
    {
        $assistant = Assistant::factory()->create();
        FlowDraft::factory()->create(['assistant_id' => $assistant->getKey()]);
        $this->limitRecords('flows', 2);
        $this->actingAsAdmin('assistant');
        Filament::setTenant($assistant);

        $this->assertTrue(FlowResource::canCreate());

        Livewire::test(ListFlows::class)
            ->assertActionVisible('create')
            ->assertDontSee('Limit reached');
    }

    public function test_race_shows_a_notification_instead_of_an_error(): void
    {
        $assistant = Assistant::factory()->create();
        $this->limitRecords('flows', 1);
        $this->actingAsAdmin('assistant');
        Filament::setTenant($assistant);
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $component = Livewire::test(CreateFlow::class)
            ->fillForm(['name' => 'Raced']);

        // Another request takes the last slot after the page was opened below the limit: the stale
        // page must reach the service and get a notification, not a 403.
        FlowDraft::factory()->create(['assistant_id' => $assistant->getKey()]);

        $component->call('create')->assertNotified(__('assistant.flows.limit.reached_title'));

        $this->assertSame(1, FlowDraft::query()->count());
    }

    public function test_settings_page_hides_the_create_flow_suffix_actions_at_the_limit(): void
    {
        $assistant = Assistant::factory()->create(['commands' => [['command' => '/go', 'type' => 'start_flow']]]);
        FlowDraft::factory()->create(['assistant_id' => $assistant->getKey()]);
        $this->actingAsAdmin('assistant');
        $url = route('filament.assistant.pages.settings', ['tenant' => $assistant]);

        $this->limitRecords('flows', 2);
        $this->get($url)->assertOk()->assertSee('createDefaultFlow')->assertSee('createCommandFlow');

        $this->limitRecords('flows', 1);
        $this->get($url)->assertOk()->assertDontSee('createDefaultFlow')->assertDontSee('createCommandFlow');
    }

    public function test_tenancy_scope_name_matches_the_assistant_panel(): void
    {
        $this->assertSame(Filament::getPanel('assistant')->getTenancyScopeName(), Assistant::PANEL_TENANCY_SCOPE);
    }

    public function test_settings_page_suffix_actions_notify_when_the_limit_is_reached_in_a_race(): void
    {
        $assistant = Assistant::factory()->create();
        $existing  = FlowDraft::factory()->create(['assistant_id' => $assistant->getKey(), 'is_active' => true]);
        // The command points at the existing flow so the page's own save, which runs first, is valid.
        $assistant->update(['commands' => [['command' => '/go', 'type' => 'start_flow', 'flow_id' => $existing->flow_id]]]);
        $limits = $this->limitRecords('flows', 2);
        $this->actingAsAdmin('assistant');
        Filament::setTenant($assistant);
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $component = Livewire::test(AssistantSettings::class);

        // Another request takes the last slot after the page showed the actions. Running an action
        // reads the limit twice (its visibility is checked while the form is built and before it
        // runs); those reads are answered "no limit", so the service's own check is the first to
        // see it.
        FlowDraft::factory()->create(['assistant_id' => $assistant->getKey()]);

        $commandKey = (string) array_key_first($component->get('data.commands'));

        foreach (['default_flow_id' => 'createDefaultFlow', "commands.{$commandKey}.flow_id" => 'createCommandFlow'] as $path => $action) {
            $limits->unlimitedAnswers = 2;

            $component
                ->callAction(TestAction::make($action)->schemaComponent($path), ['name' => 'Raced'])
                ->assertNotified(__('assistant.flows.limit.reached_title'));
        }

        $this->assertSame(2, FlowDraft::query()->count());
    }
}
