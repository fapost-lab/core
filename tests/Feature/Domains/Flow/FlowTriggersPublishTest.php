<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Models\TenantEvent;
use App\Domains\Flow\Services\FlowTriggerSet;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Flow\Services\SaveDraftService;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;

/**
 * A flow carries several triggers in its draft and in each definition; saving a draft never changes the live
 * `flow_triggers`, publishing does, keeping the rows (and their run state) of triggers that did not change.
 */
final class FlowTriggersPublishTest extends FeatureTestCase
{
    private string $tenantId;

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: $this->tenantId, schemaName: 'main'));

        $this->assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId, 'default_language' => 'en']);
        $this->app->make(CurrentAssistantInterface::class)->set($this->assistant);
    }

    public function test_a_flow_with_only_a_live_row_shows_it_in_the_builder_with_the_row_key_as_id(): void
    {
        $draft = $this->draft();
        $row   = $this->liveRow($draft, 'message', ['keywords' => ['hello'], 'phrases' => []], id: Str::ulid()->toRfc4122());

        $this->assertNull($draft->triggers);

        $user = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->get("/builder/flows/{$draft->flow_id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->has('flow.triggers', 1)
                ->where('flow.triggers.0.id', mb_strtolower((string) $row->getKey()))
                ->where('flow.triggers.0.type', 'message')
                ->where('flow.triggers.0.is_active', true)
                ->where('flow.triggers.0.priority', 100)
                ->where('flow.triggers.0.config.keywords', ['hello'])
                ->etc());
    }

    public function test_saving_the_shown_list_and_publishing_keeps_the_row_key_and_its_run_state(): void
    {
        $draft = $this->draft();
        $row   = $this->liveRow($draft, 'schedule', ['cron' => '0 9 * * *', 'timezone' => 'UTC'], id: Str::ulid()->toRfc4122());
        $row->forceFill(['next_run_at' => '2026-12-01 09:00:00', 'last_run_at' => '2026-11-30 09:00:00'])->save();

        $shown = $this->app->make(FlowTriggerSet::class)->live($draft->flow_id);
        $this->assertSame(mb_strtolower((string) $row->getKey()), $shown[0]['id']);

        $this->app->make(SaveDraftService::class)->execute($draft->flow_id, $draft->nodes, [], $shown, $draft->draft_version);
        $this->publish($draft->flow_id);

        $rows = FlowTrigger::query()->where('flow_id', $draft->flow_id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame((string) $row->getKey(), (string) $rows[0]->getKey());
        $this->assertSame('2026-12-01 09:00:00', $rows[0]->next_run_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-30 09:00:00', $rows[0]->last_run_at?->format('Y-m-d H:i:s'));
    }

    public function test_a_row_made_live_by_a_publish_is_found_by_message_routing(): void
    {
        $draft = $this->draft();
        $id    = (string) Str::uuid();
        $this->storeDraftTriggers($draft, [$this->message($id, ['keywords' => ['routing']])]);

        $this->publish($draft->flow_id);

        $found = collect($this->app->make(FlowTriggerRepositoryInterface::class)
            ->getActiveByType($this->tenantId, (string) $this->assistant->getKey(), 'message'));

        $this->assertSame([$id], $found->map(fn (FlowTrigger $row): string => mb_strtolower((string) $row->getKey()))->all());
    }

    public function test_a_row_that_existed_before_the_migration_stays_routable_after_a_publish_that_keeps_it(): void
    {
        $draft = $this->draft();
        $row   = $this->liveRow($draft, 'message', ['keywords' => ['legacy'], 'phrases' => []], id: Str::ulid()->toRfc4122());

        $this->publish($draft->flow_id);

        $found = collect($this->app->make(FlowTriggerRepositoryInterface::class)
            ->getActiveByType($this->tenantId, (string) $this->assistant->getKey(), 'message'));

        $this->assertCount(1, $found);
        $this->assertSame((string) $row->getKey(), (string) $found->first()->getKey());
    }

    public function test_publishing_a_draft_with_null_triggers_leaves_the_live_row_alone_and_records_it_in_the_definition(): void
    {
        $draft = $this->draft();
        $row   = $this->liveRow($draft, 'message', ['keywords' => ['stay'], 'phrases' => []], id: Str::ulid()->toRfc4122());
        $row->forceFill(['next_run_at' => '2026-12-01 09:00:00'])->save();
        $before = $row->fresh()->updated_at;

        $definition = $this->publish($draft->flow_id);

        $rows = FlowTrigger::query()->where('flow_id', $draft->flow_id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['stay'], $rows[0]->config['keywords']);
        $this->assertEquals($before, $rows[0]->updated_at);
        $this->assertSame($this->sortedKeys([
            [
                'id'        => mb_strtolower((string) $row->getKey()),
                'type'      => 'message',
                'is_active' => true,
                'priority'  => 100,
                'config'    => ['keywords' => ['stay'], 'phrases' => []],
            ],
        ]), $this->sortedKeys($definition->triggers), 'the definition records the live row, whatever key order its jsonb config came back in');
        $this->assertNull($draft->refresh()->triggers, 'publishing does not write the list into the draft');
    }

    public function test_saving_makes_nothing_live_until_the_publish(): void
    {
        $draft = $this->draft();
        $id    = (string) Str::uuid();

        $this->app->make(SaveDraftService::class)->execute(
            $draft->flow_id,
            $draft->nodes,
            [],
            [$this->message($id, ['keywords' => ['later']])],
            $draft->draft_version,
        );

        $this->assertSame(0, FlowTrigger::query()->count());

        $this->publish($draft->flow_id);

        $this->assertSame([$id], FlowTrigger::query()->pluck('id')->map(fn (mixed $key): string => mb_strtolower((string) $key))->all());
    }

    public function test_two_triggers_become_two_rows_and_dropping_one_deletes_its_row(): void
    {
        $draft  = $this->draft();
        $first  = (string) Str::uuid();
        $second = (string) Str::uuid();
        $this->storeDraftTriggers($draft, [
            $this->message($first, ['keywords' => ['one']]),
            $this->schedule($second),
        ]);

        $definition = $this->publish($draft->flow_id);

        $this->assertCount(2, $definition->triggers);
        $this->assertEqualsCanonicalizing([$first, $second], $this->liveIds($draft->flow_id));

        $this->storeDraftTriggers($draft, [$this->message($first, ['keywords' => ['one']])]);
        $this->publish($draft->flow_id);

        $this->assertSame([$first], $this->liveIds($draft->flow_id));

        $this->storeDraftTriggers($draft, []);
        $this->publish($draft->flow_id);

        $this->assertSame([], $this->liveIds($draft->flow_id));
        $this->assertSame([], FlowDefinition::query()->where('flow_id', $draft->flow_id)->active()->sole()->triggers);
    }

    public function test_an_unchanged_trigger_keeps_its_run_state(): void
    {
        $draft = $this->draft();
        $id    = (string) Str::uuid();
        $this->storeDraftTriggers($draft, [$this->schedule($id)]);
        $this->publish($draft->flow_id);

        FlowTrigger::query()->sole()->forceFill(['next_run_at' => '2026-12-01 09:00:00', 'last_run_at' => '2026-11-30 09:00:00'])->save();

        // The same config with its keys in another order is the same config.
        $this->storeDraftTriggers($draft, [$this->schedule($id, ['timezone' => 'UTC', 'cron' => '0 9 * * *'])]);
        $this->publish($draft->flow_id);

        $row = FlowTrigger::query()->sole();
        $this->assertSame('2026-12-01 09:00:00', $row->next_run_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-30 09:00:00', $row->last_run_at?->format('Y-m-d H:i:s'));
    }

    public function test_a_changed_config_resets_next_run_but_keeps_last_run(): void
    {
        $draft = $this->draft();
        $id    = (string) Str::uuid();
        $this->storeDraftTriggers($draft, [$this->schedule($id)]);
        $this->publish($draft->flow_id);

        FlowTrigger::query()->sole()->forceFill(['next_run_at' => '2026-12-01 09:00:00', 'last_run_at' => '2026-11-30 09:00:00'])->save();

        $this->storeDraftTriggers($draft, [$this->schedule($id, ['cron' => '0 10 * * *', 'timezone' => 'UTC'])]);
        $this->publish($draft->flow_id);

        $row = FlowTrigger::query()->sole();
        $this->assertNull($row->next_run_at);
        $this->assertSame('2026-11-30 09:00:00', $row->last_run_at?->format('Y-m-d H:i:s'));
        $this->assertSame('0 10 * * *', $row->config['cron']);
    }

    public function test_a_changed_type_resets_next_run(): void
    {
        $draft = $this->draft();
        $id    = (string) Str::uuid();
        $this->storeDraftTriggers($draft, [$this->schedule($id)]);
        $this->publish($draft->flow_id);

        FlowTrigger::query()->sole()->forceFill(['next_run_at' => '2026-12-01 09:00:00'])->save();

        $this->storeDraftTriggers($draft, [['id' => $id, 'type' => 'api', 'is_active' => true, 'priority' => 100, 'config' => ['allowed_sources' => []]]]);
        $this->publish($draft->flow_id);

        $row = FlowTrigger::query()->sole();
        $this->assertSame('api', $row->type->value);
        $this->assertNull($row->next_run_at);
    }

    public function test_changing_only_priority_or_the_active_flag_keeps_next_run(): void
    {
        $draft = $this->draft();
        $id    = (string) Str::uuid();
        $this->storeDraftTriggers($draft, [$this->schedule($id)]);
        $this->publish($draft->flow_id);

        FlowTrigger::query()->sole()->forceFill(['next_run_at' => '2026-12-01 09:00:00'])->save();

        $this->storeDraftTriggers($draft, [$this->schedule($id, priority: 7, active: false)]);
        $this->publish($draft->flow_id);

        $row = FlowTrigger::query()->sole();
        $this->assertSame(7, $row->priority);
        $this->assertFalse($row->is_active);
        $this->assertSame('2026-12-01 09:00:00', $row->next_run_at?->format('Y-m-d H:i:s'));
    }

    public function test_an_upper_case_id_in_the_draft_matches_the_lower_case_row(): void
    {
        $draft = $this->draft();
        $id    = (string) Str::uuid();
        $this->storeDraftTriggers($draft, [$this->schedule($id)]);
        $this->publish($draft->flow_id);

        $this->storeDraftTriggers($draft, [$this->schedule(mb_strtoupper($id))]);
        $this->publish($draft->flow_id);

        $this->assertSame([$id], $this->liveIds($draft->flow_id));
    }

    public function test_a_duplicate_trigger_id_is_refused_at_publish_and_changes_nothing(): void
    {
        $draft = $this->draft();
        $id    = (string) Str::uuid();
        $this->storeDraftTriggers($draft, [
            $this->message($id, ['keywords' => ['one']]),
            $this->message($id, ['keywords' => ['two']]),
        ]);

        $this->assertRefused($draft->flow_id, 'duplicate_trigger_id', "triggers.{$id}.id");

        $this->assertSame(0, FlowTrigger::query()->count());
        $this->assertSame(0, FlowDefinition::query()->where('flow_id', $draft->flow_id)->count());
    }

    public function test_an_id_that_is_not_a_uuid_is_refused_at_publish(): void
    {
        $draft = $this->draft();
        $this->storeDraftTriggers($draft, [$this->message('not-a-uuid', ['keywords' => ['one']])]);

        $this->assertRefused($draft->flow_id, 'invalid_trigger_id', 'triggers.not-a-uuid.id');
    }

    public function test_an_entry_without_an_id_is_reported_by_its_index(): void
    {
        $draft = $this->draft();
        $this->storeDraftTriggers($draft, [['type' => 'message', 'is_active' => true, 'priority' => 100, 'config' => ['keywords' => ['one']]]]);

        $this->assertRefused($draft->flow_id, 'invalid_trigger_id', 'triggers.0.id');
    }

    public function test_an_id_that_is_a_live_row_of_another_flow_is_refused(): void
    {
        $other = $this->draft();
        $taken = $this->liveRow($other, 'message', ['keywords' => ['taken'], 'phrases' => []], id: (string) Str::uuid());
        $id    = mb_strtolower((string) $taken->getKey());

        $draft = $this->draft();
        $this->storeDraftTriggers($draft, [$this->message($id, ['keywords' => ['mine']])]);

        $this->assertRefused($draft->flow_id, 'trigger_id_taken', "triggers.{$id}.id");

        $this->assertSame($other->flow_id, FlowTrigger::query()->sole()->flow_id, 'the other flow keeps its row');
    }

    public function test_an_id_that_is_a_live_row_of_this_flow_is_not_taken(): void
    {
        $draft = $this->draft();
        $row   = $this->liveRow($draft, 'message', ['keywords' => ['mine'], 'phrases' => []], id: Str::ulid()->toRfc4122());
        $id    = mb_strtolower((string) $row->getKey());
        $this->storeDraftTriggers($draft, [$this->message($id, ['keywords' => ['mine']])]);

        $this->assertSame(1, $this->publish($draft->flow_id)->version);
    }

    public function test_two_event_triggers_on_one_event_are_refused_and_two_events_are_not(): void
    {
        TenantEvent::query()->create(['tenant_id' => $this->tenantId, 'event_name' => 'order_created']);
        TenantEvent::query()->create(['tenant_id' => $this->tenantId, 'event_name' => 'order_paid']);

        $draft  = $this->draft();
        $first  = (string) Str::uuid();
        $second = (string) Str::uuid();
        $this->storeDraftTriggers($draft, [$this->event($first, 'order_created'), $this->event($second, 'order_created')]);

        $this->assertRefused($draft->flow_id, 'duplicate_event_trigger', "triggers.{$second}.config.event_name");

        $this->storeDraftTriggers($draft, [$this->event($first, 'order_created'), $this->event($second, 'order_paid')]);

        $this->assertSame(1, $this->publish($draft->flow_id)->version);
        $this->assertEqualsCanonicalizing([$first, $second], $this->liveIds($draft->flow_id));
    }

    public function test_a_contactless_trigger_in_the_list_refuses_a_node_that_needs_a_contact(): void
    {
        $draft = $this->draft([
            ['id' => 'ask', 'type' => 'send_message', 'version' => 1, 'config' => ['content_type' => 'text', 'text' => ['en' => 'Hi']]],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [['id' => 'e1', 'from' => 'ask', 'to' => 'end', 'handle' => 'default']]);
        $this->storeDraftTriggers($draft, [$this->message((string) Str::uuid(), ['keywords' => ['hi']]), $this->schedule((string) Str::uuid())]);

        $this->assertRefused($draft->flow_id, 'node_needs_contact', 'nodes.ask');
    }

    public function test_the_validate_endpoint_checks_the_given_list_and_reports_ids_in_the_path(): void
    {
        $draft   = $this->draft();
        $taken   = $this->liveRow($this->draft(), 'message', ['keywords' => ['taken'], 'phrases' => []], id: (string) Str::uuid());
        $takenId = mb_strtolower((string) $taken->getKey());
        $user    = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->postJson("/builder/flows/{$draft->flow_id}/validate", [
                'definition' => ['nodes' => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]], 'edges' => []],
                'triggers'   => [$this->message($takenId, ['keywords' => ['mine']]), $this->message('nope', ['keywords' => ['x']])],
            ])
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonFragment(['code' => 'trigger_id_taken', 'path' => "triggers.{$takenId}.id"])
            ->assertJsonFragment(['code' => 'invalid_trigger_id', 'path' => 'triggers.nope.id']);
    }

    public function test_the_validate_endpoint_without_the_key_checks_the_draft_list(): void
    {
        $draft = $this->draft();
        $this->storeDraftTriggers($draft, [$this->schedule('broken-id')]);
        $user = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->postJson("/builder/flows/{$draft->flow_id}/validate", [
                'definition' => ['nodes' => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]], 'edges' => []],
            ])
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonFragment(['code' => 'invalid_trigger_id', 'path' => 'triggers.broken-id.id']);
    }

    public function test_the_publish_endpoint_answers_422_for_a_duplicate_trigger_id(): void
    {
        $draft = $this->draft();
        $id    = (string) Str::uuid();
        $this->storeDraftTriggers($draft, [$this->schedule($id), $this->schedule($id)]);
        $user = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->postJson("/builder/flows/{$draft->flow_id}/publish")
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'duplicate_trigger_id']);

        $this->assertSame(0, FlowTrigger::query()->count());
    }

    /**
     * @param  list<array<string, mixed>>|null  $nodes
     * @param  list<array<string, mixed>>       $edges
     */
    private function draft(?array $nodes = null, array $edges = []): FlowDraft
    {
        return FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => $nodes ?? [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'        => $edges,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $triggers
     */
    private function storeDraftTriggers(FlowDraft $draft, array $triggers): void
    {
        $draft->forceFill(['triggers' => $triggers])->save();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function liveRow(FlowDraft $draft, string $type, array $config, string $id): FlowTrigger
    {
        $row = new FlowTrigger();
        $row->forceFill([
            'id'           => $id,
            'tenant_id'    => $draft->tenant_id,
            'assistant_id' => $draft->assistant_id,
            'flow_id'      => $draft->flow_id,
            'type'         => $type,
            'is_active'    => true,
            'priority'     => 100,
            'config'       => $config,
        ])->save();

        return $row;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return array<string, mixed>
     */
    private function message(string $id, array $config): array
    {
        return ['id' => $id, 'type' => 'message', 'is_active' => true, 'priority' => 100, 'config' => ['phrases' => [], ...$config]];
    }

    /**
     * @param  array<string, mixed>|null  $config
     *
     * @return array<string, mixed>
     */
    private function schedule(string $id, ?array $config = null, int $priority = 100, bool $active = true): array
    {
        return ['id' => $id, 'type' => 'schedule', 'is_active' => $active, 'priority' => $priority, 'config' => $config ?? ['cron' => '0 9 * * *', 'timezone' => 'UTC']];
    }

    /**
     * @return array<string, mixed>
     */
    private function event(string $id, string $eventName): array
    {
        return ['id' => $id, 'type' => 'event', 'is_active' => true, 'priority' => 100, 'config' => ['event_name' => $eventName]];
    }

    /**
     * @return list<string>
     */
    private function liveIds(string $flowId): array
    {
        return FlowTrigger::query()
            ->where('flow_id', $flowId)
            ->pluck('id')
            ->map(static fn (mixed $key): string => mb_strtolower((string) $key))
            ->all();
    }

    private function publish(string $flowId): FlowDefinition
    {
        try {
            return $this->app->make(PublishFlowService::class)->execute($flowId);
        } catch (FlowValidationException $exception) {
            $this->fail('Publish refused: ' . json_encode(array_map(static fn ($error): string => "{$error->code} {$error->path}: {$error->message}", $exception->errors)));
        }
    }

    private function assertRefused(string $flowId, string $code, string $path): void
    {
        try {
            $this->app->make(PublishFlowService::class)->execute($flowId);
        } catch (FlowValidationException $exception) {
            $pairs = array_map(static fn ($error): string => "{$error->code} @ {$error->path}", $exception->errors);

            $this->assertContains("{$code} @ {$path}", $pairs);

            return;
        }

        $this->fail("Publishing {$flowId} should be refused with {$code}.");
    }

    /**
     * Sorts every associative array by key, recursively, so a list read back from a
     * Postgres jsonb column (which reorders object keys) compares strictly with a literal.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sortedKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortedKeys($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function staffUser(): User
    {
        $this->seed(TenantAclSeeder::class);

        $user = User::factory()->createOne();
        $user->givePermissionTo(Permission::ManageFlowDefinitions->value, Permission::PublishFlow->value, Permission::ManageAutomations->value);
        $user->assistants()->attach((string) $this->assistant->getKey());

        return $user;
    }
}
