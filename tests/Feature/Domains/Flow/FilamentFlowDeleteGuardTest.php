<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowGroup;
use App\Domains\Flow\Models\FlowSession;
use App\Filament\Assistant\Resources\FlowGroups\Pages\ListFlowGroups;
use App\Filament\Assistant\Resources\Flows\Pages\ListFlows;
use Database\Seeders\TenantAclSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Concerns\SetsRecordLimits;
use Tests\Feature\FeatureTestCase;

/**
 * Deleting a flow in the Filament list: the guard for live sessions used to query a column that does not exist
 * (`flow_sessions.flow_id`) and fail the delete with a database error; it now asks the flow service.
 */
final class FilamentFlowDeleteGuardTest extends FeatureTestCase
{
    use SetsRecordLimits;

    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_a_flow_without_live_sessions_is_deleted(): void
    {
        $flow = $this->openList();

        Livewire::test(ListFlows::class)
            ->callAction(TestAction::make('delete')->table($flow))
            ->assertNotified();

        $this->assertModelMissing($flow);
    }

    public function test_a_flow_with_a_live_session_is_kept_and_the_user_is_told_why(): void
    {
        $flow = $this->openList();
        $this->sessionFor($flow, FlowSessionStatus::WaitingInput);

        Livewire::test(ListFlows::class)
            ->callAction(TestAction::make('delete')->table($flow))
            ->assertNotified(__('assistant.flows.delete_guard.title'));

        $this->assertModelExists($flow);
    }

    public function test_a_finished_session_does_not_hold_the_flow(): void
    {
        $flow = $this->openList();
        $this->sessionFor($flow, FlowSessionStatus::Completed);

        Livewire::test(ListFlows::class)->callAction(TestAction::make('delete')->table($flow));

        $this->assertModelMissing($flow);
    }

    public function test_bulk_deleting_flows_skips_the_ones_with_live_sessions(): void
    {
        $free = $this->openList();
        $busy = FlowDraft::factory()->create(['assistant_id' => $free->assistant_id]);
        $this->sessionFor($busy, FlowSessionStatus::Active);

        Livewire::test(ListFlows::class)
            ->selectTableRecords([$free->getKey(), $busy->getKey()])
            ->callAction(TestAction::make('delete')->table()->bulk())
            ->assertNotified(__('assistant.flows.delete_guard.title'));

        $this->assertModelMissing($free);
        $this->assertModelExists($busy);
    }

    public function test_a_bulk_delete_that_deletes_nothing_does_not_report_success(): void
    {
        $busy = $this->openList();
        $this->sessionFor($busy, FlowSessionStatus::Active);

        Livewire::test(ListFlows::class)
            ->selectTableRecords([$busy->getKey()])
            ->callAction(TestAction::make('delete')->table()->bulk())
            // Reading the notifications consumes them, so the warning is checked by the bulk test above.
            ->assertNotNotified(__('filament-actions::delete.multiple.notifications.deleted.title'));

        $this->assertModelExists($busy);
    }

    public function test_bulk_deleting_flow_groups_skips_the_ones_with_flows(): void
    {
        $flow  = $this->openList();
        $busy  = FlowGroup::query()->create(['tenant_id' => self::TENANT_ID, 'assistant_id' => $flow->assistant_id, 'name' => 'Busy']);
        $empty = FlowGroup::query()->create(['tenant_id' => self::TENANT_ID, 'assistant_id' => $flow->assistant_id, 'name' => 'Empty']);
        $flow->update(['flow_group_id' => $busy->getKey()]);

        Livewire::test(ListFlowGroups::class)
            ->selectTableRecords([$busy->getKey(), $empty->getKey()])
            ->callAction(TestAction::make('delete')->table()->bulk())
            ->assertNotified(__('assistant.flow_groups.delete_guard.title'));

        $this->assertModelExists($busy);
        $this->assertModelMissing($empty);
    }

    private function openList(): FlowDraft
    {
        $assistant = Assistant::factory()->create();
        $flow      = FlowDraft::factory()->create(['assistant_id' => $assistant->getKey()]);

        $this->actingAsAdmin('assistant');
        Filament::setTenant($assistant);
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        return $flow;
    }

    private function sessionFor(FlowDraft $flow, FlowSessionStatus $status): FlowSession
    {
        $definition = FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => $flow->flow_id,
            'version'   => 1,
            'name'      => $flow->name,
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        return FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $flow->assistant_id,
            'contact_id'         => Contact::factory()->forTenant(self::TENANT_ID)->create()->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'node-1',
            'state'              => [],
            'status'             => $status,
            'version'            => 0,
        ]);
    }
}
