<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Subflow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Tenancy\Services\PlatformSettings;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * A subflow child that stops by any way other than its own end node still hands control back to the parent
 * paused on it: nothing leaves a parent in `paused_subflow` without a living child.
 */
final class SubflowChildStopHandsBackTest extends FeatureTestCase
{
    private string $tenantId;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->app->make(NodeHandlerRegistry::class)->register(ChildStopTestHandler::class);

        $assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId]);
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);
        $this->contact = Contact::factory()->forTenant($this->tenantId)->create();
    }

    public function test_a_child_whose_handler_throws_hands_the_parent_its_failed_exit(): void
    {
        [$parent, $child] = $this->chain('throw');

        // The failure is recorded and handed back, so the job does not fail and retry.
        $this->engine()->runSession($child);

        $this->assertSame(FlowSessionStatus::Failed, $child->refresh()->status);
        $this->assertParentEnded($parent, EndStatus::Failed);
    }

    public function test_a_child_that_hits_the_iteration_limit_hands_the_parent_its_failed_exit(): void
    {
        $this->app->make(PlatformSettings::class)->update([FlowEngineInterface::MAX_ITERATIONS_SETTING => 10], 'test');
        $this->app->forgetScopedInstances();
        $this->app->make(CurrentAssistantInterface::class)->set(Assistant::query()->sole());

        [$parent, $child] = $this->chain('loop', selfLoop: true);

        $this->engine()->runSession($child);

        $this->assertSame(FlowSessionStatus::Failed, $child->refresh()->status);
        $this->assertParentEnded($parent, EndStatus::Failed);
    }

    public function test_a_child_whose_node_reports_failure_hands_the_parent_its_failed_exit(): void
    {
        [$parent, $child] = $this->chain('fail');

        $this->engine()->runSession($child);

        $this->assertSame(FlowSessionStatus::Failed, $child->refresh()->status);
        $this->assertParentEnded($parent, EndStatus::Failed);
    }

    public function test_a_child_that_runs_out_of_graph_without_an_end_node_hands_the_parent_its_success_exit(): void
    {
        [$parent, $child] = $this->chain('pass');

        $this->engine()->runSession($child);

        $this->assertSame(FlowSessionStatus::Completed, $child->refresh()->status);
        $this->assertParentEnded($parent, EndStatus::Success);
    }

    public function test_a_top_level_session_that_fails_has_no_parent_to_resume(): void
    {
        [, $child] = $this->chain('fail');
        $child->forceFill(['parent_session_id' => null])->save();

        $this->engine()->runSession($child->refresh());

        $this->assertSame(FlowSessionStatus::Failed, $child->refresh()->status);
    }

    /**
     * @return array{0: FlowSession, 1: FlowSession}
     */
    private function chain(string $mode, bool $selfLoop = false): array
    {
        $parentDefinition = $this->definition(
            [
                ['id' => 'p-sub', 'type' => 'subflow', 'version' => 1, 'config' => []],
                ['id' => 'p-ok', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
                ['id' => 'p-fail', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'failed']],
            ],
            [
                ['id' => 'pe1', 'from' => 'p-sub', 'to' => 'p-ok', 'handle' => 'success'],
                ['id' => 'pe2', 'from' => 'p-sub', 'to' => 'p-fail', 'handle' => 'failed'],
            ],
        );
        $childDefinition = $this->definition(
            [['id' => 'c1', 'type' => 'child_stop_test', 'version' => 1, 'config' => ['mode' => $mode]]],
            $selfLoop ? [['id' => 'ce1', 'from' => 'c1', 'to' => 'c1', 'handle' => 'default']] : [],
        );

        $parent = $this->seedSession($parentDefinition, 'p-sub', FlowSessionStatus::PausedSubflow);
        $child  = $this->seedSession($childDefinition, 'c1', FlowSessionStatus::Active);
        $child->forceFill(['parent_session_id' => (string) $parent->getKey()])->save();

        return [$parent, $child->refresh()];
    }

    private function assertParentEnded(FlowSession $parent, EndStatus $expected): void
    {
        $parent->refresh();

        $this->assertSame(FlowSessionStatus::Ended, $parent->status);
        $this->assertSame($expected->value, $parent->end_status);
    }

    private function engine(): FlowEngineInterface
    {
        return $this->app->make(FlowEngineInterface::class);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    private function definition(array $nodes, array $edges): FlowDefinition
    {
        return FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Child stop',
            'nodes'     => $nodes,
            'edges'     => $edges,
            'is_active' => true,
        ]);
    }

    private function seedSession(FlowDefinition $definition, string $nodeId, FlowSessionStatus $status): FlowSession
    {
        return FlowSession::query()->create([
            'tenant_id'          => $this->tenantId,
            'contact_id'         => $this->contact->getKey(),
            'assistant_id'       => $this->app->make(CurrentAssistantInterface::class)->get()->getKey(),
            'flow_id'            => $definition->flow_id,
            'flow_definition_id' => (string) $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => $nodeId,
            'state'              => [],
            'status'             => $status,
            'version'            => 1,
        ]);
    }
}

final class ChildStopTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'child_stop_test';
    }

    public function version(): int
    {
        return 1;
    }

    /**
     * @return list<int>
     */
    public function supportedVersions(): array
    {
        return [1];
    }

    public function label(): string
    {
        return 'Child stop test';
    }

    public function category(): string
    {
        return 'Logic';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];

        return match ($config['mode'] ?? 'pass') {
            'throw' => throw new RuntimeException('child exploded'),
            'fail'  => NodeExecutionResult::failed('node failed'),
            default => NodeExecutionResult::executed(),
        };
    }
}
