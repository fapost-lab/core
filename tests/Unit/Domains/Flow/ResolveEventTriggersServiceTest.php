<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Services\ResolveEventTriggersService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ResolveEventTriggersServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('flow_triggers');
        Schema::create('flow_triggers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('assistant_id')->nullable();
            $table->uuid('flow_id')->unique();
            $table->string('type', 32);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->json('config');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_resolves_all_matching_event_triggers_inside_tenant(): void
    {
        FlowTrigger::query()->create([
            'tenant_id'    => 'tenant-1',
            'assistant_id' => 'assistant-a',
            'flow_id'      => 'flow-b',
            'type'         => FlowTriggerType::Event,
            'is_active'    => true,
            'priority'     => 200,
            'config'       => ['event_name' => 'employee_registered'],
        ]);

        FlowTrigger::query()->create([
            'tenant_id'    => 'tenant-1',
            'assistant_id' => 'assistant-b',
            'flow_id'      => 'flow-a',
            'type'         => FlowTriggerType::Event,
            'is_active'    => true,
            'priority'     => 100,
            'config'       => ['event_name' => 'employee_registered'],
        ]);

        FlowTrigger::query()->create([
            'tenant_id'    => 'tenant-2',
            'assistant_id' => 'assistant-x',
            'flow_id'      => 'flow-x',
            'type'         => FlowTriggerType::Event,
            'is_active'    => true,
            'priority'     => 50,
            'config'       => ['event_name' => 'employee_registered'],
        ]);

        $resolved = app(ResolveEventTriggersService::class)->execute('tenant-1', 'employee_registered');

        $this->assertCount(2, $resolved);
        $this->assertSame('flow-a', $resolved[0]->flowId);
        $this->assertSame('flow-b', $resolved[1]->flowId);
        $this->assertSame('employee_registered', $resolved[0]->metadata['event_name']);
    }
}
