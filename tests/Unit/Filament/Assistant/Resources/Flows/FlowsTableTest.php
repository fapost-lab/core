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

    public function test_it_formats_message_trigger_hint(): void
    {
        $trigger = new FlowTrigger();
        $trigger->forceFill([
            'type'   => FlowTriggerType::Message,
            'config' => [
                'keywords' => ['help', 'support'],
                'phrases'  => ['reset password'],
            ],
        ]);

        $html = FlowsTable::triggerHint($trigger);

        $this->assertNotNull($html);
        $this->assertStringContainsString('help, support, reset password', $html);
        $this->assertStringContainsString('class="fth"', $html);
    }

    public function test_it_returns_null_for_message_trigger_with_no_keywords(): void
    {
        $trigger = new FlowTrigger();
        $trigger->forceFill([
            'type'   => FlowTriggerType::Message,
            'config' => ['keywords' => [], 'phrases' => []],
        ]);

        $this->assertNull(FlowsTable::triggerHint($trigger));
    }

    public function test_it_formats_event_trigger_hint(): void
    {
        $trigger = new FlowTrigger();
        $trigger->forceFill([
            'type'   => FlowTriggerType::Event,
            'config' => [
                'event_name' => 'employee_registered',
            ],
        ]);

        $html = FlowsTable::triggerHint($trigger);

        $this->assertNotNull($html);
        $this->assertStringContainsString('employee_registered', $html);
        $this->assertStringContainsString('class="fth"', $html);
    }

    public function test_it_formats_api_trigger_hint(): void
    {
        $trigger = new FlowTrigger();
        $trigger->forceFill([
            'type'   => FlowTriggerType::Api,
            'config' => [
                'route_key' => 'start_onboarding',
            ],
        ]);

        $html = FlowsTable::triggerHint($trigger);

        $this->assertNotNull($html);
        $this->assertStringContainsString('start_onboarding', $html);
        $this->assertStringContainsString('class="fth"', $html);
    }

    public function test_it_limits_long_keyword_lists(): void
    {
        $trigger = new FlowTrigger();
        $trigger->forceFill([
            'type'   => FlowTriggerType::Message,
            'config' => [
                'keywords' => ['a', 'b', 'c', 'd', 'e', 'f', 'g'],
                'phrases'  => [],
            ],
        ]);

        $html = FlowsTable::triggerHint($trigger);

        $this->assertNotNull($html);
        $this->assertStringContainsString('+1', $html);
    }

    public function test_it_returns_null_for_null_trigger(): void
    {
        $this->assertNull(FlowsTable::triggerHint(null));
    }

    public function test_it_keeps_grouping_available_but_disabled_by_default(): void
    {
        $livewire = Mockery::mock(HasTable::class);
        $table    = FlowsTable::configure(Table::make($livewire));

        $this->assertNull($table->getDefaultGroup());
        $this->assertArrayHasKey('flow_group_id', $table->getGroups());
    }
}
