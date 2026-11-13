<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Solution\Providers\SolutionServiceProvider;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeVendor;
use Tests\Support\InstallsShowcaseSolution;

/**
 * `GET /builder/node-types` for a tenant: Core's types, and the types of an installed Solution flagged
 * `available` only while the tenant has it activated.
 */
final class NodeTypesTenantTest extends FeatureTestCase
{
    use InstallsShowcaseSolution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->installShowcase(publish: false, alsoInstalled: [FakeVendor::solutionComposer()]);
        (new SolutionServiceProvider($this->app))->registerExtensions($this->app->make(InstalledSolutions::class)->all());
        $this->app->make(NodeHandlerRegistryInterface::class)->register(FeedbackRateNode::class, 'feedback');
    }

    protected function tearDown(): void
    {
        $this->removeShowcase();

        parent::tearDown();
    }

    public function test_the_types_of_a_solution_the_tenant_has_not_activated_are_listed_but_unavailable(): void
    {
        $response = $this->actingAs($this->staffUser())->getJson('/builder/node-types')->assertOk();

        $collect = $this->entry($response->json('data'), 'feedback.collect');
        $this->assertFalse($collect['available']);
        $this->assertFalse($collect['palette']);
        $this->assertSame(['id' => 'feedback', 'name' => 'Feedback'], $collect['solution']);
        $this->assertSame(['key' => 'feedback', 'label' => 'Feedback', 'owner' => 'feedback'], $collect['group']);
        $this->assertSame('feedback', $collect['category']);
        $this->assertSame(1, $collect['version']);

        $rate = $this->entry($response->json('data'), 'feedback.rate');
        $this->assertFalse($rate['available']);
        $this->assertFalse($rate['palette']);
    }

    public function test_the_types_of_an_activated_solution_are_available_and_in_the_palette(): void
    {
        $this->artisan('solutions:activate', ['solution' => 'feedback', '--tenant' => 'main'])->assertExitCode(0);
        $this->forgetSolutionState();

        $response = $this->actingAs($this->staffUser())->getJson('/builder/node-types')->assertOk();

        $collect = $this->entry($response->json('data'), 'feedback.collect');
        $this->assertTrue($collect['available']);
        $this->assertTrue($collect['palette']);
        $this->assertSame(['id' => 'feedback', 'name' => 'Feedback'], $collect['solution']);

        $rate = $this->entry($response->json('data'), 'feedback.rate');
        $this->assertTrue($rate['palette']);
        $this->assertSame(['key' => 'AI', 'label' => 'AI', 'owner' => null], $rate['group'], 'A node in a Core group keeps the Core group.');
        $this->assertSame(['id' => 'feedback', 'name' => 'Feedback'], $rate['solution']);
    }

    public function test_state_namespaces_are_listed_for_an_active_solution_and_absent_for_an_inactive_one(): void
    {
        $inactive = $this->actingAs($this->staffUser())->getJson('/builder/node-types')->assertOk();
        $this->assertSame([], $inactive->json('state_namespaces'));

        $this->artisan('solutions:activate', ['solution' => 'feedback', '--tenant' => 'main'])->assertExitCode(0);
        $this->forgetSolutionState();

        $active = $this->actingAs($this->staffUser())->getJson('/builder/node-types')->assertOk();
        $this->assertSame([['key' => 'feedback', 'owner' => 'feedback']], $active->json('state_namespaces'));
    }

    public function test_core_types_and_the_annotation_do_not_depend_on_the_tenants_solutions(): void
    {
        $data = $this->actingAs($this->staffUser())->getJson('/builder/node-types')->assertOk()->json('data');

        $send = $this->entry($data, 'send_message');
        $this->assertTrue($send['available']);
        $this->assertTrue($send['palette']);
        $this->assertNull($send['solution']);
        $this->assertSame(['key' => 'Conversation', 'label' => 'Conversation', 'owner' => null], $send['group']);

        $comment = $this->entry($data, 'comment');
        $this->assertSame('Annotation', $comment['category']);
        $this->assertSame(['key' => 'Annotation', 'label' => 'Annotation', 'owner' => null], $comment['group']);
        $this->assertTrue($comment['available']);
        $this->assertNull($comment['solution']);

        $this->assertFalse($this->entry($data, 'loop_end')['palette'], 'Auto-managed types stay out of the palette.');
    }

    public function test_groups_are_core_groups_in_declared_order_then_solution_groups_then_annotation(): void
    {
        $groups = $this->actingAs($this->staffUser())->getJson('/builder/node-types')->assertOk()->json('groups');

        $this->assertSame(
            ['Conversation', 'Logic', 'Data', 'Contact', 'Integration', 'AI', 'feedback', 'Annotation'],
            array_column($groups, 'key'),
        );
        $this->assertSame(['key' => 'feedback', 'label' => 'Feedback', 'owner' => 'feedback'], $groups[6]);
        $this->assertSame(['key' => 'Logic', 'label' => 'Logic', 'owner' => null], $groups[1]);
    }

    /**
     * @param  list<array<string, mixed>>  $data
     *
     * @return array<string, mixed>
     */
    private function entry(array $data, string $type): array
    {
        $entry = collect($data)->firstWhere('type', $type);
        $this->assertNotNull($entry, "No entry for {$type}.");

        return $entry;
    }

    private function staffUser(): User
    {
        $user = User::factory()->createOne();
        $user->givePermissionTo(Permission::ManageFlowDefinitions->value, Permission::PublishFlow->value);

        return $user;
    }
}

final class FeedbackRateNode extends AbstractVersionedHandler
{
    final public const string TYPE = 'feedback.rate';

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'AI';
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}
