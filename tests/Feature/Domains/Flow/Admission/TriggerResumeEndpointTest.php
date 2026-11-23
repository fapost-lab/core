<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Admission;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Models\ActivityEntry;
use App\Domains\Flow\Admission\RefusalReason;
use App\Domains\Flow\Contracts\FlowTriggerStateRepositoryInterface;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;

/**
 * A trigger the run rate breaker paused is shown in the builder and resumed from it, only by someone who manages
 * automations.
 */
final class TriggerResumeEndpointTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_resuming_needs_the_right_to_manage_automations(): void
    {
        [$draft, $triggerId] = $this->pausedSchedule();
        $author              = $this->author($draft);

        $this->actingAs($author)
            ->postJson("/builder/flows/{$draft->flow_id}/triggers/{$triggerId}/resume")
            ->assertForbidden();

        $this->assertTrue($this->states()->isPaused($triggerId));
    }

    public function test_a_manager_of_automations_resumes_the_trigger_and_gets_its_state_back(): void
    {
        [$draft, $triggerId] = $this->pausedSchedule();
        $manager             = $this->author($draft);
        $manager->givePermissionTo(Permission::ManageAutomations->value);

        $this->actingAs($manager)
            ->postJson("/builder/flows/{$draft->flow_id}/triggers/{$triggerId}/resume")
            ->assertOk()
            ->assertJsonPath("data.{$triggerId}.paused_at", null)
            ->assertJsonPath("data.{$triggerId}.refused_count", 0);

        $this->assertFalse($this->states()->isPaused($triggerId));
        $entry = ActivityEntry::query()->where('description', AuditAction::FlowTriggerResumed->value)->sole();
        $this->assertSame((string) $manager->getKey(), (string) $entry->causer_id);

        $this->actingAs($manager)
            ->postJson("/builder/flows/{$draft->flow_id}/triggers/" . Str::uuid() . '/resume')
            ->assertNotFound();
    }

    public function test_the_builder_shows_the_pause_of_the_flows_trigger(): void
    {
        [$draft, $triggerId] = $this->pausedSchedule();

        $this->actingAs($this->author($draft))
            ->get("/builder/flows/{$draft->flow_id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where("flow.triggerStates.{$triggerId}.paused_reason", RefusalReason::RateBreaker->value)
                ->etc());
    }

    private function states(): FlowTriggerStateRepositoryInterface
    {
        return $this->app->make(FlowTriggerStateRepositoryInterface::class);
    }

    private function author(FlowDraft $draft): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageFlowDefinitions->value, Permission::PublishFlow->value);
        $user->assistants()->attach($draft->assistant);

        return $user;
    }

    /**
     * @return array{FlowDraft, string}
     */
    private function pausedSchedule(): array
    {
        $assistant = Assistant::factory()->create();
        $triggerId = mb_strtolower(Str::ulid()->toRfc4122());
        $draft     = FlowDraft::factory()->create([
            'tenant_id'    => $assistant->tenant_id,
            'assistant_id' => $assistant->getKey(),
            'nodes'        => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'        => [],
            'triggers'     => [[
                'id'        => $triggerId,
                'type'      => 'schedule',
                'is_active' => true,
                'priority'  => 100,
                'config'    => ['cron' => '0 9 * * *', 'timezone' => 'UTC'],
            ]],
        ]);

        $this->app->make(PublishFlowService::class)->execute($draft->flow_id);
        $this->states()->pause($triggerId, RefusalReason::RateBreaker, CarbonImmutable::now());

        return [$draft, $triggerId];
    }
}
