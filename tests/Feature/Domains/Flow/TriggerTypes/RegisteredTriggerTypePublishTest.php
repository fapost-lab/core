<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\TriggerTypes;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Contracts\SolutionAvailabilityInterface;
use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Registry\TriggerTypeRegistry;
use App\Domains\Flow\Services\ValidateFlowService;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Str;
use Tests\Feature\Domains\Credentials\CredentialsTestCase;
use Tests\Fixtures\Solution\Triggers\FeedbackSubmittedTrigger;

/**
 * Validating and publishing a flow with a trigger of a registered type: the type checks its own config, a type of a
 * Solution that is not installed or a type nobody knows is refused, an inactive Solution only warns, the trigger gives
 * no contact, publishing it needs Manage automations, and the builder lists the type with its form.
 */
final class RegisteredTriggerTypePublishTest extends CredentialsTestCase
{
    private const string T1 = '0193a1b2-0000-7000-8000-000000000001';

    private const string T2 = '0193a1b2-0000-7000-8000-000000000002';

    private Assistant $assistant;

    private bool $feedbackActive = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId]);
        $this->app->make(TriggerTypeRegistry::class)->register(FeedbackSubmittedTrigger::class, 'feedback');

        $test = $this;
        $this->app->instance(SolutionAvailabilityInterface::class, new class ($test) implements SolutionAvailabilityInterface {
            public function __construct(private readonly RegisteredTriggerTypePublishTest $test)
            {
            }

            public function isAvailable(?string $solutionId): bool
            {
                return null === $solutionId || $this->test->feedbackActive();
            }

            public function isReady(string $solutionId): bool
            {
                return true;
            }
        });
    }

    public function feedbackActive(): bool
    {
        return $this->feedbackActive;
    }

    public function test_a_valid_config_passes_and_the_types_own_error_blocks_at_its_field(): void
    {
        $this->assertSame([], $this->codes($this->validate([$this->trigger(self::T1, ['min_score' => 3])])->errors));

        $result = $this->validate([$this->trigger(self::T1, ['min_score' => 9])]);

        $this->assertSame(['invalid_min_score'], $this->codes($result->errors));
        $this->assertSame('triggers.' . self::T1 . '.config.min_score', $result->errors[0]->path);
    }

    public function test_a_type_of_a_solution_that_is_not_installed_and_an_unknown_type_are_refused(): void
    {
        $result = $this->validate([$this->trigger(self::T1, [], 'gone.thing'), $this->trigger(self::T2, [], 'nonsense')]);

        $this->assertSame(['trigger_type_not_installed', 'unknown_trigger_type'], $this->codes($result->errors));
        $this->assertSame('triggers.' . self::T1 . '.type', $result->errors[0]->path);
    }

    public function test_an_inactive_solution_only_warns(): void
    {
        $this->feedbackActive = false;

        $result = $this->validate([$this->trigger(self::T1, ['min_score' => 3])]);

        $this->assertSame([], $this->codes($result->errors));
        $this->assertSame(['trigger_type_solution_inactive'], $this->codes($result->warnings));
    }

    public function test_a_registered_trigger_gives_no_contact(): void
    {
        $nodes = [
            ['id' => 'ask', 'type' => 'send_message', 'version' => 1, 'config' => ['content_type' => 'text', 'text' => 'Hi']],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ];

        $result = $this->app->make(ValidateFlowService::class)->execute(
            $nodes,
            [$this->trigger(self::T1, ['min_score' => 3])],
            (string) Str::uuid(),
            [['id' => 'e1', 'from' => 'ask', 'to' => 'end', 'handle' => 'default']],
        );

        $this->assertContains('node_needs_contact', $this->codes($result->errors));
    }

    public function test_publishing_a_registered_trigger_needs_the_right_to_manage_automations(): void
    {
        $draft = FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'        => [],
            'triggers'     => [$this->trigger((string) Str::uuid(), ['min_score' => 3])],
        ]);

        $this->actingAs($this->author())->postJson("/builder/flows/{$draft->flow_id}/publish")->assertForbidden();
        $this->assertSame(0, FlowTrigger::query()->count());

        $this->actingAs($this->author(Permission::ManageAutomations))->postJson("/builder/flows/{$draft->flow_id}/publish")->assertSuccessful();

        $row = FlowTrigger::query()->sole();
        $this->assertSame(FeedbackSubmittedTrigger::TYPE, $row->type);
        $this->assertNull($row->builtInType());
    }

    public function test_the_builder_lists_built_in_and_registered_types(): void
    {
        $this->feedbackActive = false;

        $data = $this->actingAs($this->author())->getJson('/builder/trigger-types')->assertOk()->json('data');

        $this->assertSame(['message', 'schedule', 'webhook', 'api', 'event', 'feedback.submitted'], array_column($data, 'type'));
        $this->assertSame([
            'type'           => 'feedback.submitted',
            'label'          => 'Feedback submitted',
            'builtin'        => false,
            'config_schema'  => ['min_score' => ['type' => 'number', 'label' => 'Minimum score']],
            'payload_fields' => ['score', 'comment'],
            'available'      => false,
            'solution'       => ['id' => 'feedback', 'name' => 'feedback'],
        ], $data[5]);
    }

    /**
     * @param  list<array<string, mixed>>  $triggers
     */
    private function validate(array $triggers): \App\Domains\Flow\DTOs\FlowValidationResultDto
    {
        return $this->app->make(ValidateFlowService::class)->execute(
            [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            $triggers,
            (string) Str::uuid(),
        );
    }

    /**
     * @param  list<FlowValidationErrorDto>  $issues
     *
     * @return list<string>
     */
    private function codes(array $issues): array
    {
        return array_map(static fn (FlowValidationErrorDto $issue): string => $issue->code, $issues);
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return array<string, mixed>
     */
    private function trigger(string $id, array $config, string $type = FeedbackSubmittedTrigger::TYPE): array
    {
        return ['id' => $id, 'type' => $type, 'is_active' => true, 'priority' => 100, 'config' => $config];
    }

    private function author(?Permission $extra = null): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_values(array_filter([
            Permission::ManageAssistants->value,
            Permission::ManageFlowDefinitions->value,
            Permission::PublishFlow->value,
            $extra?->value,
        ])));
        $user->assistants()->attach($this->assistant);

        return $user;
    }
}
