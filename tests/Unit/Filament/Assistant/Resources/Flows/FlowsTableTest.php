<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Assistant\Resources\Flows;

use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Models\FlowTrigger;
use App\Filament\Assistant\Resources\Flows\Tables\FlowsTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Mockery;
use Tests\TestCase;

final class FlowsTableTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_formats_message_trigger_summary(): void
    {
        $trigger = new FlowTrigger();
        $trigger->forceFill([
            'type'   => FlowTriggerType::Message,
            'config' => [
                'keywords' => ['help', 'support'],
                'phrases'  => ['reset password'],
            ],
        ]);

        $summary = FlowsTable::summarizeTrigger($trigger);

        $this->assertSame(
            'Message: keywords help, support; phrases reset password',
            $summary,
        );
    }

    public function test_it_formats_event_trigger_summary(): void
    {
        $trigger = new FlowTrigger();
        $trigger->forceFill([
            'type'   => FlowTriggerType::Event,
            'config' => [
                'event_name' => 'employee_registered',
            ],
        ]);

        $summary = FlowsTable::summarizeTrigger($trigger);

        $this->assertSame('Event: employee_registered', $summary);
    }

    public function test_it_limits_long_lists_in_trigger_summary(): void
    {
        $trigger = new FlowTrigger();
        $trigger->forceFill([
            'type'   => FlowTriggerType::Api,
            'config' => [
                'route_key'       => 'start_onboarding',
                'allowed_sources' => ['crm', 'portal', 'widget', 'mobile'],
            ],
        ]);

        $summary = FlowsTable::summarizeTrigger($trigger);

        $this->assertStringContainsString('API: start_onboarding; sources crm, portal, widget +1', $summary);
    }

    public function test_it_keeps_grouping_available_but_disabled_by_default(): void
    {
        $livewire = Mockery::mock(HasTable::class);
        $table    = FlowsTable::configure(Table::make($livewire));

        $this->assertNull($table->getDefaultGroup());
        $this->assertArrayHasKey('flow_group_id', $table->getGroups());
    }
}
