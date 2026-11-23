<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Jobs\Flow\StartAutomationRunJob;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;

/**
 * Re-running a failed automation run from its page: what the page offers to whom, the re-runs it lists, and who may
 * ask for one.
 */
final class FlowSessionRerunConsoleTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private Assistant $assistant;

    private FlowDefinition $definition;

    private string $triggerId;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed(TenantAclSeeder::class);
        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());

        $this->assistant  = Assistant::factory()->create();
        $draft            = FlowDraft::factory()->create(['assistant_id' => $this->assistant->getKey(), 'name' => 'Orders']);
        $this->definition = FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => $draft->flow_id,
            'version'   => 3,
            'name'      => 'Orders',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);
        $this->triggerId = (string) FlowTrigger::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $this->assistant->getKey(),
            'flow_id'      => $draft->flow_id,
            'type'         => 'event',
            'is_active'    => true,
            'priority'     => 0,
            'config'       => ['event_name' => 'order.paid'],
        ])->getKey();
    }

    public function test_a_failed_run_offers_its_re_run_on_the_flows_active_version(): void
    {
        $run = $this->automationRun(FlowSessionStatus::Failed, ['flow_version' => 2]);

        $this->actingAs($this->admin())
            ->get($this->viewUrl($run))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rerun', [
                    'allowed'       => true,
                    'refusal'       => null,
                    'targetVersion' => 3,
                    'attempt'       => 1,
                    'url'           => "/assistant/{$this->assistant->getKey()}/flow-sessions/{$run->getKey()}/rerun",
                ])
                ->where('reruns', [])
                ->where('rerunOf', null)
                ->etc());
    }

    public function test_a_run_that_cannot_be_re_run_says_why_and_a_conversation_offers_nothing(): void
    {
        $this->actingAs($this->admin())
            ->get($this->viewUrl($this->automationRun(FlowSessionStatus::Completed)))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rerun.allowed', false)
                ->where('rerun.refusal', 'not_failed')
                ->etc());

        $this->actingAs($this->admin())
            ->get($this->viewUrl($this->automationRun(FlowSessionStatus::Failed, ['trigger_payload' => null])))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rerun.refusal', 'no_payload')->etc());

        $conversation = $this->automationRun(FlowSessionStatus::Failed, [
            'run_kind'   => FlowRunKind::Conversation,
            'contact_id' => Contact::factory()->create()->getKey(),
        ]);

        $this->actingAs($this->admin())
            ->get($this->viewUrl($conversation))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rerun', null)->etc());
    }

    public function test_re_running_queues_the_run_and_goes_back_to_the_page(): void
    {
        $run = $this->automationRun(FlowSessionStatus::Failed);

        $this->actingAs($this->admin())
            ->post($this->viewUrl($run) . '/rerun', ['attempt' => 1])
            ->assertRedirect("/assistant/{$this->assistant->getKey()}/flow-sessions/{$run->getKey()}")
            ->assertInertiaFlash('success', trans('console.flow_sessions.rerun.queued'));

        Queue::assertPushed(StartAutomationRunJob::class, fn (StartAutomationRunJob $job): bool => 'rerun:' . $run->getKey() . ':1' === $job->fireKey);
    }

    public function test_a_refused_re_run_queues_nothing_and_says_why(): void
    {
        $run = $this->automationRun(FlowSessionStatus::Active);

        $this->actingAs($this->admin())
            ->post($this->viewUrl($run) . '/rerun', ['attempt' => 1])
            ->assertRedirect()
            ->assertInertiaFlash('error', trans('console.flow_sessions.rerun.refusals.not_failed'));

        Queue::assertNotPushed(StartAutomationRunJob::class);
    }

    public function test_a_request_replayed_after_its_re_run_started_starts_nothing_more(): void
    {
        $run = $this->automationRun(FlowSessionStatus::Failed);

        $this->actingAs($this->admin())->post($this->viewUrl($run) . '/rerun', ['attempt' => 1])->assertRedirect();
        // The queued run has started.
        $this->automationRun(FlowSessionStatus::Active, ['fire_key' => 'rerun:' . $run->getKey() . ':1']);

        $this->actingAs($this->admin())
            ->post($this->viewUrl($run) . '/rerun', ['attempt' => 1])
            ->assertRedirect()
            ->assertInertiaFlash('error', trans('console.flow_sessions.rerun.refusals.already_rerun'));
        $this->actingAs($this->admin())->post($this->viewUrl($run) . '/rerun')->assertSessionHasErrors('attempt');

        Queue::assertPushed(StartAutomationRunJob::class, 1);

        $this->actingAs($this->admin())
            ->get($this->viewUrl($run))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rerun.attempt', 2)->etc());
    }

    public function test_the_source_lists_its_re_runs_and_a_re_run_links_back(): void
    {
        $source = $this->automationRun(FlowSessionStatus::Failed);
        $rerun  = $this->automationRun(FlowSessionStatus::Completed, ['fire_key' => 'rerun:' . $source->getKey() . ':1']);
        // Another source's re-run is not this run's.
        $this->automationRun(FlowSessionStatus::Completed, ['fire_key' => 'rerun:' . Str::ulid()->toRfc4122() . ':1']);

        $this->actingAs($this->admin())
            ->get($this->viewUrl($source))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('reruns', 1)
                ->where('reruns.0.id', (string) $rerun->getKey())
                ->where('reruns.0.status', 'completed')
                ->where('reruns.0.isRunning', false)
                ->where('reruns.0.url', "/assistant/{$this->assistant->getKey()}/flow-sessions/{$rerun->getKey()}")
                ->etc());

        $this->actingAs($this->admin())
            ->get($this->viewUrl($rerun))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rerunOf', [
                    'id'  => (string) $source->getKey(),
                    'url' => "/assistant/{$this->assistant->getKey()}/flow-sessions/{$source->getKey()}",
                ])
                ->etc());
    }

    public function test_a_listed_re_run_says_whether_it_is_still_running(): void
    {
        $source = $this->automationRun(FlowSessionStatus::Failed);
        $this->automationRun(FlowSessionStatus::Pending, ['fire_key' => 'rerun:' . $source->getKey() . ':1']);
        $this->automationRun(FlowSessionStatus::WaitingInput, ['fire_key' => 'rerun:' . $source->getKey() . ':2']);
        $this->automationRun(FlowSessionStatus::Paused, ['fire_key' => 'rerun:' . $source->getKey() . ':3']);

        $this->actingAs($this->admin())
            ->get($this->viewUrl($source))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('reruns', 3)
                ->where('reruns.0.isRunning', true)
                ->where('reruns.1.isRunning', true)
                ->where('reruns.2.isRunning', false)
                ->etc());
    }

    public function test_a_run_that_is_never_re_run_lists_no_re_runs(): void
    {
        // Only a failed root run is re-run: a run keyed to a completed one or to a subflow child is not looked up.
        $completed = $this->automationRun(FlowSessionStatus::Completed);
        $this->automationRun(FlowSessionStatus::Completed, ['fire_key' => 'rerun:' . $completed->getKey() . ':1']);
        $child = $this->automationRun(FlowSessionStatus::Failed, ['parent_session_id' => $completed->getKey()]);
        $this->automationRun(FlowSessionStatus::Completed, ['fire_key' => 'rerun:' . $child->getKey() . ':1']);

        foreach ([$completed, $child] as $session) {
            $this->actingAs($this->admin())
                ->get($this->viewUrl($session))
                ->assertInertia(fn (AssertableInertia $page) => $page->where('reruns', [])->etc());
        }
    }

    public function test_re_running_needs_the_rerun_permission_beside_the_sessions_one(): void
    {
        $run    = $this->automationRun(FlowSessionStatus::Failed);
        $viewer = $this->userWith([Permission::ViewFlowSessions]);

        $this->actingAs($viewer)
            ->get($this->viewUrl($run))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rerun', null)->etc());
        $this->actingAs($viewer)->post($this->viewUrl($run) . '/rerun', ['attempt' => 1])->assertForbidden();

        $this->actingAs($this->userWith([Permission::RerunFlow]))->post($this->viewUrl($run) . '/rerun', ['attempt' => 1])->assertForbidden();

        $allowed = $this->userWith([Permission::ViewFlowSessions, Permission::RerunFlow]);
        $this->actingAs($allowed)
            ->get($this->viewUrl($run))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rerun.allowed', true)->etc());
        $this->actingAs($allowed)->post($this->viewUrl($run) . '/rerun', ['attempt' => 1])->assertRedirect();

        Queue::assertPushed(StartAutomationRunJob::class, 1);
    }

    public function test_another_assistants_run_is_not_found(): void
    {
        $sibling = $this->automationRun(FlowSessionStatus::Failed, ['assistant_id' => Assistant::factory()->create()->getKey()]);

        $this->actingAs($this->admin())->post($this->viewUrl($sibling) . '/rerun', ['attempt' => 1])->assertNotFound();

        Queue::assertNotPushed(StartAutomationRunJob::class);
    }

    private function viewUrl(FlowSession $session): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/flow-sessions/{$session->getKey()}");
    }

    /**
     * A root automation run of the trigger, failed unless said otherwise, with the payload it started with.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function automationRun(FlowSessionStatus $status, array $attributes = []): FlowSession
    {
        return FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $this->assistant->getKey(),
            'flow_definition_id' => $this->definition->getKey(),
            'flow_version'       => $this->definition->version,
            'current_node_id'    => 'node-1',
            'state'              => [],
            'status'             => $status,
            'run_kind'           => FlowRunKind::Automation,
            'trigger_id'         => $this->triggerId,
            'fire_key'           => (string) Str::ulid(),
            'trigger_payload'    => ['flow' => ['event' => ['order' => 7]]],
            'version'            => 1,
            ...$attributes,
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        // Opening an assistant's console needs ManageAssistants; the screen asks for its own permissions on top.
        $user->givePermissionTo([Permission::ManageAssistants->value, ...array_map(static fn (Permission $permission): string => $permission->value, $permissions)]);
        $user->assistants()->attach($this->assistant);

        return $user;
    }
}
