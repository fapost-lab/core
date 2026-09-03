<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Services\ResolveEventTriggersService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ResolveEventTriggersServiceTest extends TestCase
{
    use DatabaseTransactions;

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
        $tenantId      = (string) Str::uuid();
        $otherTenantId = (string) Str::uuid();
        $flowAId       = (string) Str::uuid();
        $flowBId       = (string) Str::uuid();

        FlowTrigger::query()->create([
            'tenant_id'    => $tenantId,
            'assistant_id' => (string) Str::uuid(),
            'flow_id'      => $flowBId,
            'type'         => FlowTriggerType::Event,
            'is_active'    => true,
            'priority'     => 200,
            'config'       => ['event_name' => 'employee_registered'],
        ]);

        FlowTrigger::query()->create([
            'tenant_id'    => $tenantId,
            'assistant_id' => (string) Str::uuid(),
            'flow_id'      => $flowAId,
            'type'         => FlowTriggerType::Event,
            'is_active'    => true,
            'priority'     => 100,
            'config'       => ['event_name' => 'employee_registered'],
        ]);

        FlowTrigger::query()->create([
            'tenant_id'    => $otherTenantId,
            'assistant_id' => (string) Str::uuid(),
            'flow_id'      => (string) Str::uuid(),
            'type'         => FlowTriggerType::Event,
            'is_active'    => true,
            'priority'     => 50,
            'config'       => ['event_name' => 'employee_registered'],
        ]);

        $resolved = app(ResolveEventTriggersService::class)->execute($tenantId, 'employee_registered');

        $this->assertCount(2, $resolved);
        $this->assertSame($flowAId, $resolved[0]->flowId);
        $this->assertSame($flowBId, $resolved[1]->flowId);
        $this->assertSame('employee_registered', $resolved[0]->metadata['event_name']);
    }
}
