<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Actions\CreateFlowAction;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Tenancy\Services\UnlimitedTenantLimits;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Tests\Feature\Concerns\SetsRecordLimits;
use Tests\Feature\FeatureTestCase;

/**
 * The flow limit counts drafts tenant-wide and is checked in CreateFlowAction, the only place a
 * flow is created.
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
}
