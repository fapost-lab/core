<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Expression\ExpressionEngineRegistry;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Repositories\FlowDraftRepository;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Flow\Contracts\ExpressionEngineInterface;
use Fapost\Foundation\Flow\DTO\ExpressionContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Domains\Credentials\CredentialsTestCase;

/**
 * Publishing what a draft changes against what runs now: a new saved credential in a `call` node needs
 * `UseCredentials`, a changed automatic trigger (any type but `message`) needs `ManageAutomations`, a new or changed
 * message to tags or a segment, or a change to what leads to one, needs `ManageBroadcast`; turning on a flow with an
 * automatic trigger needs `ManageAutomations` too. The content manager holds both, an admin passes everything.
 */
final class FlowAutomationPermissionsTest extends CredentialsTestCase
{
    private const string TRIGGER = '0193a6a4-6f1e-7c3b-9a52-1d4e8b7c2f01';

    private const string OTHER_TRIGGER = '0193a6a4-6f1e-7c3b-9a52-1d4e8b7c2f02';

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId]);
    }

    public function test_publishing_a_new_credential_reference_needs_the_right_to_use_credentials(): void
    {
        $credential = $this->credential();
        $draft      = $this->draft($this->callNodes((string) $credential->id));

        $this->publish($this->author(), $draft)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.credentials'));

        $this->publish($this->author(Permission::UseCredentials), $draft)->assertSuccessful();
    }

    public function test_republishing_the_same_credential_reference_or_dropping_it_needs_no_right(): void
    {
        $credential = $this->credential();
        $draft      = $this->draft($this->callNodes((string) $credential->id));
        $this->publish($this->author(Permission::UseCredentials), $draft)->assertSuccessful();

        // Same reference, the node edited around it.
        $draft->forceFill(['nodes' => $this->callNodes((string) $credential->id, 'GET https://api.example.com/other')])->save();
        $this->publish($this->author(), $draft)->assertSuccessful();

        $draft->forceFill(['nodes' => $this->callNodes(null)])->save();
        $this->publish($this->author(), $draft)->assertSuccessful();
    }

    public function test_switching_to_another_credential_needs_the_right(): void
    {
        $first  = $this->credential();
        $second = $this->credential();
        $draft  = $this->draft($this->callNodes((string) $first->id));
        $this->publish($this->author(Permission::UseCredentials), $draft)->assertSuccessful();

        $draft->forceFill(['nodes' => $this->callNodes((string) $second->id)])->save();

        $this->publish($this->author(), $draft)->assertForbidden();
    }

    public function test_adding_an_automatic_trigger_needs_the_right_to_manage_automations(): void
    {
        $draft = $this->draft(triggers: [$this->schedule((string) Str::uuid())]);

        $this->publish($this->author(), $draft)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.automations'));
        $this->assertSame(0, FlowTrigger::query()->count());

        $this->publish($this->author(Permission::ManageAutomations), $draft)->assertSuccessful();
        $this->assertSame(1, FlowTrigger::query()->count());
    }

    public function test_an_unchanged_automatic_trigger_republishes_without_the_right_and_a_change_to_it_does_not(): void
    {
        $id    = (string) Str::uuid();
        $draft = $this->draft(triggers: [$this->schedule($id)]);
        $this->publish($this->author(Permission::ManageAutomations), $draft)->assertSuccessful();

        // Nodes and a message trigger change; the automatic trigger does not.
        $draft->forceFill([
            'nodes'    => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'cancelled']]],
            'triggers' => [$this->schedule($id), $this->message((string) Str::uuid())],
        ])->save();
        $this->publish($this->author(), $draft)->assertSuccessful();
        $this->publish($this->author(), $draft)->assertSuccessful();

        foreach ([
            'config'   => [$this->schedule($id, ['cron' => '0 10 * * *', 'timezone' => 'UTC']), $this->message((string) Str::uuid())],
            'priority' => [$this->schedule($id, priority: 5)],
            'active'   => [$this->schedule($id, active: false)],
            'type'     => [$this->message($id)],
            'removed'  => [],
        ] as $change => $triggers) {
            $draft->forceFill(['triggers' => $triggers])->save();

            $this->publish($this->author(), $draft)->assertForbidden();
            $row = FlowTrigger::query()->findOrFail($id);
            $this->assertSame(['schedule', true, 100, '0 9 * * *'], [$row->type->value, $row->is_active, $row->priority, $row->config['cron'] ?? null], "A refused {$change} change must leave the live row alone.");
        }
    }

    public function test_a_draft_saved_after_the_publish_read_it_is_checked_as_saved(): void
    {
        $draft = $this->draft();

        // The builder's publish reads the draft, and a save lands right after that read, before the publish locks it:
        // the save adds an automatic trigger. What goes live must be checked, not what was read.
        $this->app->instance(FlowDraftRepositoryInterface::class, new class ($this->app->make(FlowDraftRepository::class), $this->schedule((string) Str::uuid())) implements FlowDraftRepositoryInterface {
            /**
             * @param  array<string, mixed>  $trigger
             */
            public function __construct(private readonly FlowDraftRepository $inner, private readonly array $trigger)
            {
            }

            public function findByFlowId(string $flowId): FlowDraft
            {
                $read = $this->inner->findByFlowId($flowId);
                FlowDraft::query()->whereKey($read->getKey())->firstOrFail()->forceFill(['triggers' => [$this->trigger]])->save();

                return $read;
            }

            public function isSwitchedOff(string $flowId): bool
            {
                return $this->inner->isSwitchedOff($flowId);
            }
        });

        $this->publish($this->author(), $draft)->assertForbidden();
        $this->assertSame(0, FlowTrigger::query()->count());
    }

    public function test_publishing_a_new_or_changed_message_to_tags_needs_the_right_to_manage_broadcasts(): void
    {
        $draft = $this->draft($this->audienceNodes('Hello'), attributes: ['edges' => $this->audienceEdges()]);

        $this->publish($this->author(), $draft)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts'));

        $this->publish($this->author(Permission::ManageBroadcast), $draft)->assertSuccessful();

        // Unchanged: republished without the right; a node edited around it too.
        $this->publish($this->author(), $draft)->assertSuccessful();

        $draft->forceFill(['nodes' => $this->audienceNodes('Hello again')])->save();
        $this->publish($this->author(), $draft)->assertForbidden();

        $draft->forceFill(['nodes' => $this->audienceNodes('Hello', ['vip', 'beta'])])->save();
        $this->publish($this->author(), $draft)->assertForbidden();

        // Dropping the audience needs nothing.
        $draft->forceFill(['nodes' => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]], 'edges' => []])->save();
        $this->publish($this->author(), $draft)->assertSuccessful();
    }

    /**
     * A placed template is checked as it will be published, after expansion: a template can bring a message to tags, and
     * a host's parameter value rewrites its text.
     */
    public function test_a_message_to_tags_a_template_brings_or_a_parameter_rewrites_needs_the_right(): void
    {
        $template = FlowDraft::factory()->template()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => [
                [...$this->audienceNodes('Hello')[0], 'id' => 't-tell', 'parameters' => [['key' => 'greeting', 'label' => null, 'field' => 'text']]],
                ['id' => 't-done', 'type' => 'template_output', 'version' => 1, 'config' => ['name' => 'done']],
            ],
            'edges' => [['id' => 'te1', 'from' => 't-tell', 'to' => 't-done', 'handle' => 'default']],
        ]);
        $this->publish($this->author(Permission::ManageBroadcast), $template)->assertSuccessful();

        $reference = static fn (array $values): array => ['id' => 'ref', 'type' => 'template', 'version' => 1, 'config' => array_filter([
            'template_flow_id' => $template->flow_id,
            'outputs'          => [['name' => 'done', 'label' => null]],
            'parameter_values' => $values,
        ])];
        $host = $this->draft(
            [$reference([]), ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            attributes: ['edges' => [['id' => 'e1', 'from' => 'ref', 'to' => 'end', 'handle' => 'done']]],
        );

        $this->publish($this->author(), $host)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts'));
        $this->publish($this->author(Permission::ManageBroadcast), $host)->assertSuccessful();
        $this->publish($this->author(), $host)->assertSuccessful();

        $host->forceFill(['nodes' => [$reference(['greeting' => ['en' => 'Pay now']]), $host->nodes[1]]])->save();

        $this->publish($this->author(), $host)->assertForbidden();
    }

    /**
     * What leads to a message to tags or a segment decides what it says and how often it goes out: an upstream node, an
     * edge into the cone, a removed predecessor or a node that leads back to it through a loop needs the right.
     */
    public function test_changing_what_leads_to_a_message_to_tags_needs_the_right(): void
    {
        $draft = $this->draft($this->upstreamNodes('a'), attributes: ['edges' => $this->upstreamEdges()]);
        $this->publish($this->author(Permission::ManageBroadcast), $draft)->assertSuccessful();

        // The assign that writes what the broadcast reads.
        $draft->forceFill(['nodes' => $this->upstreamNodes('b')])->save();
        $this->publish($this->author(), $draft)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts_upstream'));

        // A node put between them, so a new edge leads into the broadcast.
        $draft->forceFill([
            'nodes' => [...$this->upstreamNodes('a'), $this->assignNode('more', 'c')],
            'edges' => [
                ['id' => 'e0', 'from' => 'set', 'to' => 'more', 'handle' => 'default'],
                ['id' => 'e3', 'from' => 'more', 'to' => 'tell', 'handle' => 'default'],
                ['id' => 'e1', 'from' => 'tell', 'to' => 'end', 'handle' => 'default'],
            ],
        ])->save();
        $this->publish($this->author(), $draft)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts_upstream'));

        // The predecessor removed.
        $draft->forceFill(['nodes' => $this->audienceNodes('Hello {{session.x}}'), 'edges' => $this->audienceEdges()])->save();
        $this->publish($this->author(), $draft)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts_upstream'));
    }

    public function test_a_node_after_a_message_to_tags_in_a_loop_leads_back_to_it_and_needs_the_right(): void
    {
        $draft = $this->draft($this->loopNodes('a'), attributes: ['edges' => $this->loopEdges()]);
        $this->publish($this->author(Permission::ManageBroadcast), $draft)->assertSuccessful();

        // Unchanged: the loop_end's iterator name publishing wrote is not a change.
        $this->publish($this->author(), $draft)->assertSuccessful();

        $draft->forceFill(['nodes' => $this->loopNodes('b')])->save();
        $this->publish($this->author(), $draft)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts_upstream'));
    }

    /**
     * Edits outside the cone need nothing: a node after the broadcast, a node on another branch, the canvas position, the
     * label, edge ids and the key order of a config. Moving the broadcast to the other branch changes only the links
     * into it, and needs the right.
     */
    public function test_changes_outside_what_leads_to_a_message_to_tags_need_no_right_and_a_relinked_one_does(): void
    {
        $nodes = static fn (string $after, string $other): array => [
            ['id' => 'check', 'type' => 'branch', 'version' => 1, 'config' => ['rules' => [
                ['left' => 'session.x', 'operator' => 'eq', 'value' => 'a', 'handle' => 'high'],
            ]]],
            ['id' => 'tell', 'type' => 'send_message', 'version' => 1, 'config' => [
                'content_type' => 'text',
                'text'         => ['en' => 'Hello'],
                'addressee'    => ['kind' => 'tags', 'tags' => ['vip']],
            ]],
            ['id' => 'after', 'type' => 'assign', 'version' => 1, 'config' => ['operations' => [['variable' => ['name' => 'y', 'storage' => 'session'], 'value' => $after]]]],
            ['id' => 'other', 'type' => 'assign', 'version' => 1, 'config' => ['operations' => [['variable' => ['name' => 'z', 'storage' => 'session'], 'value' => $other]]]],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ];
        $edges = [
            ['id' => 'e1', 'from' => 'check', 'to' => 'tell', 'handle' => 'high'],
            ['id' => 'e2', 'from' => 'tell', 'to' => 'after', 'handle' => 'default'],
            ['id' => 'e3', 'from' => 'check', 'to' => 'other', 'handle' => 'default'],
            ['id' => 'e4', 'from' => 'other', 'to' => 'end', 'handle' => 'default'],
        ];

        $draft = $this->draft($nodes('a', 'a'), attributes: ['edges' => $edges]);
        $this->publish($this->author(Permission::ManageBroadcast), $draft)->assertSuccessful();

        $draft->forceFill(['nodes' => $nodes('b', 'b')])->save();
        $this->publish($this->author(), $draft)->assertSuccessful();

        $moved    = $nodes('b', 'b');
        $moved[0] = ['label' => 'Check', 'position' => ['x' => 40, 'y' => 80], ...$moved[0]];
        $moved[1] = ['id' => 'tell', 'type' => 'send_message', 'version' => 1, 'position' => ['x' => 10, 'y' => 20], 'label' => 'Tell them', 'config' => [
            'addressee'    => ['tags' => ['vip'], 'kind' => 'tags'],
            'text'         => ['en' => 'Hello'],
            'content_type' => 'text',
        ]];
        $draft->forceFill(['nodes' => $moved, 'edges' => [...array_map(static fn (array $edge): array => ['id' => 'x' . $edge['id'], ...array_diff_key($edge, ['id' => true])], $edges)]])->save();
        $this->publish($this->author(), $draft)->assertSuccessful();

        // The same nodes, but the broadcast now hangs off the other branch: the links into it changed.
        $swapped              = $edges;
        $swapped[0]['handle'] = 'default';
        $swapped[2]['handle'] = 'high';
        $draft->forceFill(['nodes' => $nodes('b', 'b'), 'edges' => $swapped])->save();
        $this->publish($this->author(), $draft)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts_upstream'));
    }

    /**
     * The triggers start the graph that leads to the broadcast and set how often it goes out, so on a flow with one any
     * trigger change needs the right, `message` included; the expression engine reads every placeholder in the cone.
     */
    public function test_a_trigger_or_engine_change_on_a_flow_with_a_message_to_tags_needs_the_right(): void
    {
        $engine = new class () implements ExpressionEngineInterface {
            public function id(): string
            {
                return 'publish_right_test';
            }

            public function version(): int
            {
                return 1;
            }

            public function evaluate(string $source, ExpressionContext $context): mixed
            {
                return $source;
            }

            public function validate(string $source): void
            {
            }

            public function extractReferences(string $source): array
            {
                return [];
            }
        };
        $this->app->make(ExpressionEngineRegistry::class)->register($engine);

        $draft = $this->draft($this->upstreamNodes('a'), [$this->message(self::TRIGGER)], ['edges' => $this->upstreamEdges()]);
        $this->publish($this->author(Permission::ManageBroadcast), $draft)->assertSuccessful();

        // Unchanged, with its trigger: no right.
        $this->publish($this->author(), $draft)->assertSuccessful();

        $draft->forceFill(['triggers' => [[...$this->message(self::TRIGGER), 'config' => ['phrases' => ['hi'], 'keywords' => []]]]])->save();
        $this->publish($this->author(), $draft)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts_upstream'));

        $draft->forceFill(['triggers' => [$this->message(self::TRIGGER)], 'expression_engine' => 'publish_right_test'])->save();
        $this->publish($this->author(), $draft)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts_upstream'));

        // Without a message to tags, the same changes need nothing.
        $plain = $this->draft(attributes: ['triggers' => [[...$this->message(self::OTHER_TRIGGER), 'config' => ['phrases' => ['bye'], 'keywords' => []]]]]);
        $this->publish($this->author(), $plain)->assertSuccessful();
        $plain->forceFill(['triggers' => [[...$this->message(self::OTHER_TRIGGER), 'config' => ['phrases' => ['ciao'], 'keywords' => []]]]])->save();
        $this->publish($this->author(), $plain)->assertSuccessful();
    }

    /**
     * A template placed before a host's broadcast: the host's parameter values rewrite the copy, which is in the cone.
     * Publishing the template republishes its hosts without their check, so a changed template needs the right while
     * any host's active definition has a message to tags or a segment; an unchanged one does not.
     */
    public function test_a_template_leading_to_a_hosts_message_to_tags_needs_the_right(): void
    {
        $templateNodes = static fn (string $text): array => [
            ['id' => 't-loop', 'type' => 'loop', 'version' => 1, 'config' => [
                'mode'          => 'counted',
                'iterator_name' => 'i',
                'count_source'  => ['type' => 'literal', 'value' => 2],
            ]],
            ['id' => 't-le', 'type' => 'loop_end', 'version' => 1, 'config' => ['loop_node_id' => 't-loop']],
            ['id' => 't-say', 'type' => 'send_message', 'version' => 1, 'parameters' => [['key' => 'greeting', 'label' => null, 'field' => 'text']], 'config' => [
                'content_type' => 'text',
                'text'         => ['en' => $text],
                'addressee'    => ['kind' => 'contact', 'contact' => '{{flow.customer_id}}'],
            ]],
            ['id' => 't-done', 'type' => 'template_output', 'version' => 1, 'config' => ['name' => 'done']],
        ];
        $template = FlowDraft::factory()->template()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => $templateNodes('Hi'),
            'edges'        => [
                ['id' => 'te0', 'from' => 't-loop', 'to' => 't-say', 'handle' => 'loop'],
                ['id' => 'te1', 'from' => 't-say', 'to' => 't-le', 'handle' => 'default'],
                ['id' => 'te2', 'from' => 't-loop', 'to' => 't-done', 'handle' => 'default'],
            ],
        ]);
        $this->publish($this->author(), $template)->assertSuccessful();

        $reference = static fn (array $values): array => ['id' => 'ref', 'type' => 'template', 'version' => 1, 'config' => array_filter([
            'template_flow_id' => $template->flow_id,
            'outputs'          => [['name' => 'done', 'label' => null]],
            'parameter_values' => $values,
        ])];
        $host = $this->draft(
            [$reference([]), ...$this->audienceNodes('Hello')],
            attributes: ['edges' => [
                ['id' => 'e0', 'from' => 'ref', 'to' => 'tell', 'handle' => 'done'],
                ...$this->audienceEdges(),
            ]],
        );
        $this->publish($this->author(Permission::ManageBroadcast), $host)->assertSuccessful();
        $this->publish($this->author(), $host)->assertSuccessful();

        $host->forceFill(['nodes' => [$reference(['greeting' => ['en' => 'Pay now']]), ...$this->audienceNodes('Hello')]])->save();
        $this->publish($this->author(), $host)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts_upstream'));

        // The template itself: unchanged it republishes freely, changed it needs the right.
        $this->publish($this->author(), $template)->assertSuccessful();

        $template->forceFill(['nodes' => $templateNodes('Hi there')])->save();
        $this->publish($this->author(), $template)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts_upstream'));
        $this->publish($this->author(Permission::ManageBroadcast), $template)->assertSuccessful();

        // The host's live version now comes from the template's republish: an unchanged host still needs nothing.
        $host->forceFill(['nodes' => [$reference([]), ...$this->audienceNodes('Hello')]])->save();
        $this->publish($this->author(Permission::ManageBroadcast), $host)->assertSuccessful();
        $this->publish($this->author(Permission::ManageBroadcast), $template)->assertSuccessful();
        $this->publish($this->author(), $host)->assertSuccessful();
    }

    /**
     * A template whose hosts hold no message to tags or a segment, and which holds none itself, leads to no broadcast:
     * changing its graph needs nothing. (Its engine cannot change on its own here: a host's republish refuses a
     * template whose engine differs from its own.)
     */
    public function test_a_changed_template_whose_hosts_have_no_message_to_tags_publishes_without_the_right(): void
    {
        $templateNodes = static fn (string $value): array => [
            ['id' => 't-set', 'type' => 'assign', 'version' => 1, 'config' => [
                'operations' => [['variable' => ['name' => 'x', 'storage' => 'session'], 'value' => $value]],
            ]],
            ['id' => 't-done', 'type' => 'template_output', 'version' => 1, 'config' => ['name' => 'done']],
        ];
        $template = FlowDraft::factory()->template()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => $templateNodes('a'),
            'edges'        => [['id' => 'te0', 'from' => 't-set', 'to' => 't-done', 'handle' => 'default']],
        ]);
        $this->publish($this->author(), $template)->assertSuccessful();

        $host = $this->draft(
            [
                ['id' => 'ref', 'type' => 'template', 'version' => 1, 'config' => [
                    'template_flow_id' => $template->flow_id,
                    'outputs'          => [['name' => 'done', 'label' => null]],
                ]],
                ...$this->audienceNodes('Hello', addressee: ['kind' => 'contact', 'contact' => '{{flow.customer_id}}']),
            ],
            attributes: ['edges' => [
                ['id' => 'e0', 'from' => 'ref', 'to' => 'tell', 'handle' => 'done'],
                ...$this->audienceEdges(),
            ]],
        );
        $this->publish($this->author(), $host)->assertSuccessful();

        $template->forceFill(['nodes' => $templateNodes('b')])->save();
        $this->publish($this->author(), $template)->assertSuccessful();
    }

    /**
     * With no host at all, a template's own message to tags is judged by its cone: what an upstream node of the
     * template writes into the text needs the right, and an unchanged republish does not.
     */
    public function test_a_templates_own_message_to_tags_with_a_changed_cone_needs_the_right(): void
    {
        $templateNodes = fn (string $value): array => [
            $this->assignNode('t-set', $value),
            ['id' => 't-say', 'type' => 'send_message', 'version' => 1, 'config' => [
                'content_type' => 'text',
                'text'         => ['en' => 'Hello {{session.x}}'],
                'addressee'    => ['kind' => 'tags', 'tags' => ['vip']],
            ]],
            ['id' => 't-done', 'type' => 'template_output', 'version' => 1, 'config' => ['name' => 'done']],
        ];
        $template = FlowDraft::factory()->template()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => $templateNodes('a'),
            'edges'        => [
                ['id' => 'te0', 'from' => 't-set', 'to' => 't-say', 'handle' => 'default'],
                ['id' => 'te1', 'from' => 't-say', 'to' => 't-done', 'handle' => 'default'],
            ],
        ]);

        // New template with a message to tags: the right is needed, and then the version is live.
        $this->publish($this->author(), $template)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts'));
        $this->publish($this->author(Permission::ManageBroadcast), $template)->assertSuccessful();
        $this->publish($this->author(), $template)->assertSuccessful();

        // Only an upstream node changed; the message node's own fields did not.
        $template->forceFill(['nodes' => $templateNodes('b')])->save();
        $this->publish($this->author(), $template)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts_upstream'));
        $this->publish($this->author(Permission::ManageBroadcast), $template)->assertSuccessful();
    }

    /**
     * A subflow seeds the variables of the flow it starts: a caller of a flow with a message to tags is checked like
     * what leads to that message, and a caller of a flow without one is not.
     */
    public function test_a_subflow_that_starts_a_flow_with_a_message_to_tags_needs_the_right(): void
    {
        $broadcaster = $this->draft($this->audienceNodes('Hi {{flow.offer}}'), attributes: ['edges' => $this->audienceEdges()]);
        $this->publish($this->author(Permission::ManageBroadcast), $broadcaster)->assertSuccessful();
        $quiet = $this->draft();
        $this->publish($this->author(), $quiet)->assertSuccessful();

        $caller = static fn (string $flowId, string $offer): array => [
            ['id' => 'sub', 'type' => 'subflow', 'version' => 1, 'config' => [
                'flow_id'       => $flowId,
                'timeout'       => 'PT1H',
                'input_mapping' => ['offer' => $offer],
            ]],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ];
        $edges = [['id' => 'e1', 'from' => 'sub', 'to' => 'end', 'handle' => 'default']];

        $draft = $this->draft($caller($broadcaster->flow_id, '10%'), attributes: ['edges' => $edges]);
        $this->publish($this->author(), $draft)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts_upstream'));
        $this->publish($this->author(Permission::ManageBroadcast), $draft)->assertSuccessful();
        $this->publish($this->author(), $draft)->assertSuccessful();

        $draft->forceFill(['nodes' => $caller($broadcaster->flow_id, 'anything')])->save();
        $this->publish($this->author(), $draft)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts_upstream'));

        // Pinned to the version with the broadcast, after a later version dropped it: the pinned version decides.
        $broadcaster->forceFill(['nodes' => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]], 'edges' => []])->save();
        $this->publish($this->author(), $broadcaster)->assertSuccessful();
        $pinned                              = $caller($broadcaster->flow_id, 'pinned');
        $pinned[0]['config']['flow_version'] = 1;
        $pinnedDraft                         = $this->draft($pinned, attributes: ['edges' => $edges]);
        $this->publish($this->author(), $pinnedDraft)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts_upstream'));
        $this->publish($this->author(), $this->draft($caller($broadcaster->flow_id, 'latest'), attributes: ['edges' => $edges]))->assertSuccessful();

        $plain = $this->draft($caller($quiet->flow_id, '10%'), attributes: ['edges' => $edges]);
        $this->publish($this->author(), $plain)->assertSuccessful();
        $plain->forceFill(['nodes' => $caller($quiet->flow_id, 'anything')])->save();
        $this->publish($this->author(), $plain)->assertSuccessful();
    }

    /**
     * A `start_flow` node seeds the variables of the flow it starts like a `subflow` does: what it hands over is in the
     * cone, and a node that starts a flow without such a message is not checked.
     */
    public function test_a_start_flow_that_starts_a_flow_with_a_message_to_tags_needs_the_right(): void
    {
        $broadcaster = $this->draft($this->audienceNodes('Hi {{flow.offer}}'), attributes: ['edges' => $this->audienceEdges()]);
        $this->publish($this->author(Permission::ManageBroadcast), $broadcaster)->assertSuccessful();
        $quiet = $this->draft();
        $this->publish($this->author(), $quiet)->assertSuccessful();

        $caller = static fn (string $flowId, string $offer): array => [
            ['id' => 'start', 'type' => 'start_flow', 'version' => 1, 'config' => [
                'flow_id'       => $flowId,
                'addressee'     => ['kind' => 'contact', 'contact' => '{{flow.customer_id}}'],
                'input_mapping' => ['offer' => $offer],
            ]],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ];
        $edges = [['id' => 'e1', 'from' => 'start', 'to' => 'end', 'handle' => 'default']];

        $draft = $this->draft($caller($broadcaster->flow_id, '10%'), attributes: ['edges' => $edges]);
        $this->publish($this->author(), $draft)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts_upstream'));
        $this->publish($this->author(Permission::ManageBroadcast), $draft)->assertSuccessful();
        $this->publish($this->author(), $draft)->assertSuccessful();

        $draft->forceFill(['nodes' => $caller($broadcaster->flow_id, 'anything')])->save();
        $this->publish($this->author(), $draft)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts_upstream'));

        $plain = $this->draft($caller($quiet->flow_id, '10%'), attributes: ['edges' => $edges]);
        $this->publish($this->author(), $plain)->assertSuccessful();
        $plain->forceFill(['nodes' => $caller($quiet->flow_id, 'anything')])->save();
        $this->publish($this->author(), $plain)->assertSuccessful();
    }

    /**
     * A caller holds no message of its own, but how often it starts the broadcasting flow follows its triggers, and
     * every placeholder it hands over is read by its expression engine.
     */
    public function test_a_trigger_or_engine_change_on_a_caller_of_a_flow_with_a_message_to_tags_needs_the_right(): void
    {
        $engine = new class () implements ExpressionEngineInterface {
            public function id(): string
            {
                return 'publish_right_caller_test';
            }

            public function version(): int
            {
                return 1;
            }

            public function evaluate(string $source, ExpressionContext $context): mixed
            {
                return $source;
            }

            public function validate(string $source): void
            {
            }

            public function extractReferences(string $source): array
            {
                return [];
            }
        };
        $this->app->make(ExpressionEngineRegistry::class)->register($engine);

        $broadcaster = $this->draft($this->audienceNodes('Hi {{flow.offer}}'), attributes: ['edges' => $this->audienceEdges()]);
        $this->publish($this->author(Permission::ManageBroadcast), $broadcaster)->assertSuccessful();

        $draft = $this->draft(
            [
                ['id' => 'sub', 'type' => 'subflow', 'version' => 1, 'config' => [
                    'flow_id'       => $broadcaster->flow_id,
                    'timeout'       => 'PT1H',
                    'input_mapping' => ['offer' => '10%'],
                ]],
                ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            [$this->message(self::TRIGGER)],
            ['edges' => [['id' => 'e1', 'from' => 'sub', 'to' => 'end', 'handle' => 'default']]],
        );
        $this->publish($this->author(Permission::ManageBroadcast), $draft)->assertSuccessful();

        // Unchanged, with its trigger: no right.
        $this->publish($this->author(), $draft)->assertSuccessful();

        $draft->forceFill(['triggers' => [[...$this->message(self::TRIGGER), 'config' => ['phrases' => ['hi'], 'keywords' => []]]]])->save();
        $this->publish($this->author(), $draft)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts_upstream'));

        $draft->forceFill(['triggers' => [$this->message(self::TRIGGER)], 'expression_engine' => 'publish_right_caller_test'])->save();
        $this->publish($this->author(), $draft)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.broadcasts_upstream'));
        $this->publish($this->author(Permission::ManageBroadcast), $draft)->assertSuccessful();
    }

    public function test_a_flow_without_audience_messages_publishes_without_the_right_to_broadcast(): void
    {
        $draft = $this->draft($this->audienceNodes('Hello', addressee: ['kind' => 'contact', 'contact' => '{{flow.customer_id}}']), attributes: ['edges' => $this->audienceEdges()]);

        $this->publish($this->author(), $draft)->assertSuccessful();
    }

    public function test_the_content_manager_publishes_automations_and_credentials_but_does_not_manage_credentials(): void
    {
        $credential = $this->credential();
        $draft      = $this->draft($this->callNodes((string) $credential->id), [$this->schedule((string) Str::uuid())]);

        $manager = User::factory()->create();
        $manager->assignRole(RoleEnum::ContentManager->value);
        $manager->assistants()->attach($this->assistant);

        $this->publish($manager, $draft)->assertSuccessful();
        $this->actingAs($manager)->getJson('/builder/credentials')->assertOk();
        $this->assertFalse(Gate::forUser($manager)->allows('viewAny', $credential::class));
    }

    public function test_an_admin_passes_every_check(): void
    {
        $credential = $this->credential();
        $draft      = $this->draft($this->callNodes((string) $credential->id), [$this->schedule((string) Str::uuid())]);

        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);

        $this->publish($admin, $draft)->assertSuccessful();
    }

    public function test_turning_on_a_flow_with_an_automatic_trigger_needs_the_right_and_turning_it_off_does_not(): void
    {
        $automated = $this->draft(attributes: ['is_active' => false]);
        $this->liveRow($automated, 'schedule');
        $plain = $this->draft(attributes: ['is_active' => false]);
        $this->liveRow($plain, 'message');

        $editor = $this->author();

        $this->actingAs($editor)->patch($this->activityUrl($automated), ['active' => true])->assertForbidden();
        $this->assertFalse((bool) $automated->refresh()->is_active);

        $this->actingAs($editor)->patch($this->activityUrl($plain), ['active' => true])->assertRedirect();
        $this->assertTrue((bool) $plain->refresh()->is_active);

        $automated->forceFill(['is_active' => true])->save();
        $this->actingAs($editor)->patch($this->activityUrl($automated), ['active' => false])->assertRedirect();
        $this->assertFalse((bool) $automated->refresh()->is_active);

        $this->actingAs($this->author(Permission::ManageAutomations))->patch($this->activityUrl($automated), ['active' => true])->assertRedirect();
        $this->assertTrue((bool) $automated->refresh()->is_active);
    }

    public function test_reading_run_payloads_needs_both_session_and_payload_rights(): void
    {
        $session = new FlowSession();

        $sessions = User::factory()->create();
        $sessions->givePermissionTo(Permission::ViewFlowSessions->value);
        $payloads = User::factory()->create();
        $payloads->givePermissionTo(Permission::ViewRunPayloads->value);
        $both = User::factory()->create();
        $both->givePermissionTo(Permission::ViewFlowSessions->value, Permission::ViewRunPayloads->value);
        $manager = User::factory()->create();
        $manager->assignRole(RoleEnum::ContentManager->value);
        $analyst = User::factory()->create();
        $analyst->assignRole(RoleEnum::Analyst->value);

        $this->assertFalse(Gate::forUser($sessions)->allows('viewPayloads', $session));
        $this->assertFalse(Gate::forUser($payloads)->allows('viewPayloads', $session));
        $this->assertTrue(Gate::forUser($both)->allows('viewPayloads', $session));
        $this->assertTrue(Gate::forUser($manager)->allows('viewPayloads', $session));
        $this->assertFalse(Gate::forUser($analyst)->allows('viewPayloads', $session));
    }

    /**
     * A flow author who may publish, with an extra right when given.
     */
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

    private function publish(User $user, FlowDraft $draft): TestResponse
    {
        return $this->actingAs($user)->postJson("/builder/flows/{$draft->flow_id}/publish");
    }

    /**
     * @param  list<array<string, mixed>>|null  $nodes
     * @param  list<array<string, mixed>>|null  $triggers
     * @param  array<string, mixed>             $attributes
     */
    private function draft(?array $nodes = null, ?array $triggers = null, array $attributes = []): FlowDraft
    {
        return FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => $nodes ?? [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'        => null === $nodes ? [] : [['id' => 'e1', 'from' => 'call', 'to' => 'end', 'handle' => 'success']],
            'triggers'     => $triggers ?? [],
            ...$attributes,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function callNodes(?string $credentialId, string $target = 'GET https://api.example.com/me'): array
    {
        return [
            ['id' => 'call', 'type' => 'call', 'version' => 1, 'config' => array_filter([
                'transport'     => 'http',
                'target'        => $target,
                'credential_id' => $credentialId,
            ], static fn (mixed $value): bool => null !== $value)],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ];
    }

    /**
     * @param  list<string>               $tags
     * @param  array<string, mixed>|null  $addressee
     *
     * @return list<array<string, mixed>>
     */
    private function audienceNodes(string $text, array $tags = ['vip'], ?array $addressee = null): array
    {
        return [
            ['id' => 'tell', 'type' => 'send_message', 'version' => 1, 'config' => [
                'content_type' => 'text',
                'text'         => ['en' => $text],
                'addressee'    => $addressee ?? ['kind' => 'tags', 'tags' => $tags],
            ]],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function audienceEdges(): array
    {
        return [['id' => 'e1', 'from' => 'tell', 'to' => 'end', 'handle' => 'default']];
    }

    /**
     * An assign that writes what the broadcast after it reads.
     *
     * @return list<array<string, mixed>>
     */
    private function upstreamNodes(string $value): array
    {
        return [$this->assignNode('set', $value), ...$this->audienceNodes('Hello {{session.x}}')];
    }

    /**
     * @return list<array<string, string>>
     */
    private function upstreamEdges(): array
    {
        return [['id' => 'e0', 'from' => 'set', 'to' => 'tell', 'handle' => 'default'], ...$this->audienceEdges()];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignNode(string $id, string $value): array
    {
        return ['id' => $id, 'type' => 'assign', 'version' => 1, 'config' => [
            'operations' => [['variable' => ['name' => 'x', 'storage' => 'session'], 'value' => $value]],
        ]];
    }

    /**
     * A counted loop whose body sends to tags, then runs `after` before the loop_end jumps back.
     *
     * @return list<array<string, mixed>>
     */
    private function loopNodes(string $after): array
    {
        return [
            ['id' => 'loop', 'type' => 'loop', 'version' => 1, 'config' => [
                'mode'          => 'counted',
                'iterator_name' => 'i',
                'count_source'  => ['type' => 'literal', 'value' => 3],
            ]],
            $this->audienceNodes('Hello {{session.x}}')[0],
            $this->assignNode('after', $after),
            ['id' => 'le', 'type' => 'loop_end', 'version' => 1, 'config' => ['loop_node_id' => 'loop']],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function loopEdges(): array
    {
        return [
            ['id' => 'e1', 'from' => 'loop', 'to' => 'tell', 'handle' => 'loop'],
            ['id' => 'e2', 'from' => 'tell', 'to' => 'after', 'handle' => 'default'],
            ['id' => 'e3', 'from' => 'after', 'to' => 'le', 'handle' => 'default'],
            ['id' => 'e4', 'from' => 'loop', 'to' => 'end', 'handle' => 'default'],
        ];
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
    private function message(string $id): array
    {
        return ['id' => $id, 'type' => 'message', 'is_active' => true, 'priority' => 100, 'config' => ['phrases' => ['hello'], 'keywords' => []]];
    }

    private function liveRow(FlowDraft $draft, string $type): void
    {
        $row = new FlowTrigger();
        $row->forceFill([
            'id'           => (string) Str::uuid(),
            'tenant_id'    => $draft->tenant_id,
            'assistant_id' => $draft->assistant_id,
            'flow_id'      => $draft->flow_id,
            'type'         => $type,
            'is_active'    => true,
            'priority'     => 100,
            'config'       => 'schedule' === $type ? ['cron' => '0 9 * * *', 'timezone' => 'UTC'] : ['phrases' => ['hello'], 'keywords' => []],
        ])->save();
    }

    private function activityUrl(FlowDraft $draft): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/flows/{$draft->getKey()}/active");
    }
}
