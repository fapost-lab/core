<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Templates;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Audit\Models\ActivityEntry;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Enums\FlowKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Exceptions\InvalidTriggerPayloadException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Flow\Services\SaveDraftService;
use App\Domains\Flow\Templates\TemplateExpander;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * Flow templates expanded at publish time (task flow-templates, AC-01..AC-07): the copy in the host's definition,
 * running it in the host's session, two copies side by side, republishing hosts when the template is published,
 * refusing a template publish that breaks a host, and the references publishing refuses.
 */
final class FlowTemplatePublishTest extends FeatureTestCase
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private string $tenantId;

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: $this->tenantId, schemaName: 'main'));
        $this->app->make(NodeHandlerRegistry::class)->register(TraceTestHandler::class);

        $this->assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId, 'default_language' => 'en']);
        $this->app->make(CurrentAssistantInterface::class)->set($this->assistant);
    }

    public function test_publishing_a_host_embeds_a_copy_with_reissued_ids_and_rewired_edges(): void
    {
        $template = $this->publishedTemplate();
        $host     = $this->hostDraft($template->flow_id);

        $definition = $this->publish($host->flow_id);

        $nodes  = collect($definition->nodes)->keyBy('id');
        $copies = $nodes->filter(static fn (array $node): bool => isset($node['origin']));

        $this->assertCount(4, $copies, 'every template node is copied');
        foreach ($copies as $id => $copy) {
            $this->assertMatchesRegularExpression(self::UUID_PATTERN, (string) $id);
            $this->assertStringNotContainsString('.', (string) $id);
            $this->assertSame('ref', $copy['origin']['ref']);
            $this->assertSame($template->flow_id, $copy['origin']['template_flow_id']);
            $this->assertSame(1, $copy['origin']['template_version']);
        }

        $ask    = $copies->firstWhere('origin.node_id', 't-ask');
        $button = $ask['config']['buttons'][0]['id'];
        $this->assertMatchesRegularExpression(self::UUID_PATTERN, $button);
        $this->assertNotSame('b-yes', $button);

        $edges = collect($definition->edges);
        $this->assertTrue($edges->contains(fn (array $e): bool => 'ref' === $e['from'] && 'enter' === $e['handle'] && $e['to'] === $ask['id']));
        $this->assertTrue($edges->contains(fn (array $e): bool => $e['from'] === $ask['id'] && $e['handle'] === $button), 'button handle follows the re-issued button id');
        $this->assertFalse($edges->contains(fn (array $e): bool => 'ref' === $e['from'] && 'done' === $e['handle']), 'named edges of the reference are replaced');

        $done = $copies->firstWhere('origin.node_id', 't-done');
        $this->assertTrue($edges->contains(fn (array $e): bool => $e['from'] === $done['id'] && 'h-after' === $e['to'] && 'default' === $e['handle']));

        $reference = $nodes->get('ref');
        $this->assertSame(1, $reference['config']['template_version']);
        $this->assertSame(['done' => 'h-after'], $reference['config']['exits']);
        $this->assertSame(
            [$template->flow_id],
            DB::table('flow_template_usages')->where('host_flow_id', $host->flow_id)->pluck('template_flow_id')->all(),
        );
    }

    public function test_a_session_runs_the_template_copy_in_its_own_session_and_leaves_by_the_named_output(): void
    {
        $template = $this->publishedTemplate(withButtons: false);
        $host     = $this->hostDraft($template->flow_id);

        $definition = $this->publish($host->flow_id);
        $contact    = Contact::factory()->forTenant($this->tenantId)->create();

        $this->app->make(FlowEngineInterface::class)->start($definition, $contact);

        $sessions = FlowSession::query()->where('contact_id', $contact->getKey())->get();
        $this->assertCount(1, $sessions, 'no child session is started');
        $this->assertSame(FlowSessionStatus::Completed, $sessions[0]->status);
        $this->assertSame('in-template', $sessions[0]->state['flow']['inside'] ?? null);
        $this->assertSame('after', $sessions[0]->state['flow']['host'] ?? null);
    }

    public function test_an_end_node_inside_a_template_ends_the_host_session(): void
    {
        $template = $this->templateDraft(
            nodes: [
                ['id' => 't-in', 'type' => 'trace_test', 'version' => 1, 'config' => ['key' => 'inside', 'value' => 'in-template']],
                ['id' => 't-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'cancelled']],
            ],
            edges: [
                ['id' => 'te1', 'from' => 't-in', 'to' => 't-end', 'handle' => 'default'],
            ],
        );
        $this->publish($template->flow_id);

        $host = FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => [
                ['id' => 'ref', 'type' => 'template', 'version' => 1, 'config' => ['template_flow_id' => $template->flow_id, 'outputs' => []]],
                ['id' => 'h-after', 'type' => 'trace_test', 'version' => 1, 'config' => ['key' => 'host', 'value' => 'after']],
            ],
            'edges' => [],
        ]);
        // h-after is a second entry node; the engine starts at the reference only when it is the sole entry.
        $host->update(['nodes' => [$host->nodes[0]]]);
        $definition = $this->publish($host->flow_id);
        $contact    = Contact::factory()->forTenant($this->tenantId)->create();

        $this->app->make(FlowEngineInterface::class)->start($definition, $contact);

        $session = FlowSession::query()->where('contact_id', $contact->getKey())->sole();
        $this->assertSame(FlowSessionStatus::Ended, $session->status);
        $this->assertSame('cancelled', $session->end_status);
        $this->assertArrayNotHasKey('host', $session->state['flow'] ?? []);
    }

    public function test_one_template_placed_twice_gets_two_copies_whose_ids_and_buttons_never_collide(): void
    {
        $template = $this->publishedTemplate();
        $host     = FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => [
                $this->reference('ref-a', $template->flow_id),
                $this->reference('ref-b', $template->flow_id),
                ['id' => 'h-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges' => [
                ['id' => 'he1', 'from' => 'ref-a', 'to' => 'ref-b', 'handle' => 'done'],
                ['id' => 'he2', 'from' => 'ref-b', 'to' => 'h-end', 'handle' => 'done'],
            ],
        ]);

        $definition = $this->publish($host->flow_id);

        $copies = collect($definition->nodes)->filter(static fn (array $node): bool => isset($node['origin']));
        $a      = $copies->where('origin.ref', 'ref-a');
        $b      = $copies->where('origin.ref', 'ref-b');

        $this->assertCount(4, $a);
        $this->assertCount(4, $b);
        $this->assertSame([], array_intersect($a->keys()->all(), $b->keys()->all()));

        $buttonA = $a->firstWhere('origin.node_id', 't-ask')['config']['buttons'][0]['id'];
        $buttonB = $b->firstWhere('origin.node_id', 't-ask')['config']['buttons'][0]['id'];
        $this->assertNotSame($buttonA, $buttonB);
    }

    public function test_publishing_a_template_republishes_its_hosts_from_their_active_definition(): void
    {
        $template = $this->publishedTemplate(withButtons: false);
        $host     = $this->hostDraft($template->flow_id);
        $first    = $this->publish($host->flow_id);

        $contact = Contact::factory()->forTenant($this->tenantId)->create();
        $running = FlowSession::query()->create([
            'tenant_id'          => $this->tenantId,
            'contact_id'         => $contact->getKey(),
            'assistant_id'       => $this->assistant->getKey(),
            'flow_definition_id' => $first->getKey(),
            'current_node_id'    => 'ref',
            'flow_version'       => $first->version,
            'status'             => FlowSessionStatus::WaitingInput,
            'state'              => [],
            'version'            => 1,
        ]);

        // Unpublished work in the host's draft must not reach the republished version.
        $host->update(['name' => 'Renamed in draft', 'nodes' => [...$host->nodes, ['id' => 'h-wip', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'failed']]]]);

        $template->update(['nodes' => collect($template->nodes)->map(static function (array $node): array {
            if ('t-in' === $node['id']) {
                $node['config']['value'] = 'v2';
            }

            return $node;
        })->all()]);
        $this->publish($template->flow_id);
        // A second template publish collapses and re-expands the republished host losslessly.
        $this->publish($template->flow_id);

        $active = FlowDefinition::query()->where('flow_id', $host->flow_id)->active()->sole();
        $this->assertSame(3, $active->version);
        $this->assertSame($template->flow_id, $active->republished_by_template_flow_id);
        $this->assertSame(3, $active->republished_by_template_version);
        $this->assertSame($first->name, $active->name);
        $this->assertNotContains('h-wip', collect($active->nodes)->pluck('id')->all());

        $copyIn = collect($active->nodes)->firstWhere('origin.node_id', 't-in');
        $this->assertSame('v2', $copyIn['config']['value']);
        $this->assertSame(
            collect($first->nodes)->firstWhere('origin.node_id', 't-in')['id'],
            $copyIn['id'],
            'a copied node keeps its id across template versions',
        );
        $this->assertSame(['done' => 'h-after'], collect($active->nodes)->firstWhere('id', 'ref')['config']['exits']);
        $done = collect($active->nodes)->firstWhere('origin.node_id', 't-done');
        $this->assertTrue(collect($active->edges)->contains(fn (array $e): bool => $e['from'] === $done['id'] && 'h-after' === $e['to']));

        $this->assertSame((string) $first->getKey(), (string) $running->fresh()->flow_definition_id, 'a running session stays on its version');

        $newContact = Contact::factory()->forTenant($this->tenantId)->create();
        $this->app->make(FlowEngineInterface::class)->start($active, $newContact);
        $this->assertSame('v2', FlowSession::query()->where('contact_id', $newContact->getKey())->sole()->state['flow']['inside'] ?? null);
    }

    public function test_a_template_publish_that_breaks_a_host_is_refused_with_the_host_listed(): void
    {
        $template = $this->publishedTemplate(withButtons: false);
        $host     = $this->hostDraft($template->flow_id);
        $this->publish($host->flow_id);

        $template->update(['nodes' => collect($template->nodes)->map(static function (array $node): array {
            if ('t-done' === $node['id']) {
                $node['config']['name'] = 'finished';
            }

            return $node;
        })->all()]);

        try {
            $this->app->make(PublishFlowService::class)->execute($template->flow_id);
            $this->fail('the publish should be refused');
        } catch (FlowValidationException $exception) {
            $error = $exception->errors[0];
            $this->assertSame('template_breaks_flow', $error->code);
            $this->assertSame("template.hosts.{$host->flow_id}", $error->path);
            $this->assertStringContainsString('no output', $error->message);
        }

        $this->assertSame(1, FlowDefinition::query()->where('flow_id', $template->flow_id)->max('version'), 'nothing is published');
        $this->assertSame(1, FlowDefinition::query()->where('flow_id', $host->flow_id)->max('version'));
        $this->assertSame(2, ActivityEntry::query()->where('description', 'flow.published')->count(), 'a refused publish leaves no entry');
    }

    public function test_a_template_publish_records_its_own_publish_and_each_host_republish(): void
    {
        $template = $this->publishedTemplate(withButtons: false);
        $host     = $this->hostDraft($template->flow_id);
        $this->publish($host->flow_id);
        ActivityEntry::query()->delete();

        $this->publish($template->flow_id);

        $entries = ActivityEntry::query()->orderBy('id')->get();

        $this->assertSame(['flow.published', 'flow.published'], $entries->pluck('description')->all());
        $this->assertTrue($entries->every(fn (ActivityEntry $entry): bool => 'flows' === $entry->log_name
            && (string) $this->assistant->getKey() === $entry->assistant_id));

        $templateEntry = $entries->firstWhere('subject_id', (string) $template->getKey());
        $this->assertNotNull($templateEntry);
        $this->assertSame(['version' => 2], $templateEntry->properties?->toArray());

        $hostEntry = $entries->firstWhere('subject_id', (string) $host->getKey());
        $this->assertNotNull($hostEntry);
        $this->assertSame('Host flow', $hostEntry->subject_label);
        $this->assertSame([
            'version'                         => 2,
            'republished_by_template_flow_id' => $template->flow_id,
            'republished_by_template_version' => 2,
        ], $hostEntry->properties?->toArray());
        $this->assertStringNotContainsString('"nodes"', (string) json_encode($entries->map->getAttributes()));
    }

    public function test_publishing_refuses_a_nested_template_a_foreign_template_an_unpublished_one_and_an_output_in_a_flow(): void
    {
        $inner = $this->publishedTemplate();

        $outer = $this->templateDraft(
            nodes: [
                $this->reference('nested', $inner->flow_id),
                ['id' => 'o-done', 'type' => 'template_output', 'version' => 1, 'config' => ['name' => 'done']],
            ],
            edges: [['id' => 'oe1', 'from' => 'nested', 'to' => 'o-done', 'handle' => 'done']],
        );
        $this->assertRefused($outer->flow_id, 'template_nested');

        $otherAssistant = Assistant::factory()->create(['tenant_id' => $this->tenantId]);
        $foreign        = FlowDraft::factory()->template()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $otherAssistant->getKey(),
            'nodes'        => [['id' => 'f-done', 'type' => 'template_output', 'version' => 1, 'config' => ['name' => 'done']]],
        ]);
        $this->publish($foreign->flow_id);
        $this->assertRefused($this->hostDraft($foreign->flow_id)->flow_id, 'template_cross_assistant');

        $unpublished = $this->templateDraft(nodes: [['id' => 'u-done', 'type' => 'template_output', 'version' => 1, 'config' => ['name' => 'done']]], edges: []);
        $this->assertRefused($this->hostDraft($unpublished->flow_id)->flow_id, 'template_not_published');

        $flowWithOutput = FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => [['id' => 'x', 'type' => 'template_output', 'version' => 1, 'config' => ['name' => 'done']]],
        ]);
        $this->assertRefused($flowWithOutput->flow_id, 'template_output_outside_template');
    }

    public function test_a_template_is_never_found_as_a_flow_to_start_and_has_no_trigger(): void
    {
        $template = $this->publishedTemplate();

        $this->assertSame(FlowKind::Template, FlowDefinition::query()->where('flow_id', $template->flow_id)->sole()->kind);
        $this->assertNull($this->app->make(FlowDefinitionRepositoryInterface::class)->findLatestActiveByFlowId($template->flow_id));
        $this->assertNotNull($this->app->make(FlowDefinitionRepositoryInterface::class)->findLatestPublishedByFlowId($template->flow_id));

        $this->expectException(InvalidTriggerPayloadException::class);
        $this->app->make(SaveDraftService::class)->execute(
            flowId: $template->flow_id,
            nodes: $template->nodes,
            edges: $template->edges,
            trigger: ['type' => 'message', 'config' => ['keywords' => ['hi']]],
            expectedDraftVersion: $template->draft_version,
        );
    }

    public function test_the_builder_validation_reports_template_rules_before_publishing(): void
    {
        $expander = $this->app->make(TemplateExpander::class);

        $templateErrors = $expander->draftErrors(
            nodes: [
                ['id' => 'a', 'type' => 'trace_test', 'version' => 1, 'config' => []],
                ['id' => 'b', 'type' => 'template_output', 'version' => 1, 'config' => ['name' => 'Bad Name']],
            ],
            edges: [['id' => 'e', 'from' => 'b', 'to' => 'a', 'handle' => 'default']],
            assistantId: (string) $this->assistant->getKey(),
            isTemplate: true,
        );
        $codes = array_map(static fn ($error): string => $error->code, $templateErrors);
        $this->assertContains('template_output_invalid_name', $codes);
        $this->assertContains('template_output_has_edges', $codes);

        $template   = $this->publishedTemplate(withButtons: false);
        $flowErrors = $expander->draftErrors(
            nodes: [$this->reference('ref', $template->flow_id), ['id' => 'x', 'type' => 'trace_test', 'version' => 1, 'config' => []]],
            edges: [['id' => 'e', 'from' => 'ref', 'to' => 'x', 'handle' => 'gone']],
            assistantId: (string) $this->assistant->getKey(),
            isTemplate: false,
        );
        $this->assertSame(['template_unknown_output'], array_map(static fn ($error): string => $error->code, $flowErrors));
    }

    private function publishedTemplate(bool $withButtons = true): FlowDraft
    {
        $ask = $withButtons
            ? ['id' => 't-ask', 'type' => 'send_message', 'version' => 1, 'config' => [
                'content_type'  => 'text',
                'text'          => ['en' => 'Continue?'],
                'keyboard_type' => 'inline',
                'buttons'       => [['id' => 'b-yes', 'label' => ['en' => 'Yes'], 'value' => 'yes']],
            ]]
            : ['id' => 't-ask', 'type' => 'trace_test', 'version' => 1, 'config' => ['key' => 'asked', 'value' => 'yes']];

        $template = $this->templateDraft(
            nodes: [
                $ask,
                ['id' => 't-in', 'type' => 'trace_test', 'version' => 1, 'config' => ['key' => 'inside', 'value' => 'in-template']],
                ['id' => 't-loopless', 'type' => 'trace_test', 'version' => 1, 'config' => ['key' => 'second', 'value' => 'yes']],
                ['id' => 't-done', 'type' => 'template_output', 'version' => 1, 'config' => ['name' => 'done', 'label' => 'Done']],
            ],
            edges: [
                ['id' => 'te1', 'from' => 't-ask', 'to' => 't-in', 'handle' => $withButtons ? 'b-yes' : 'default'],
                ['id' => 'te2', 'from' => 't-in', 'to' => 't-loopless', 'handle' => 'default'],
                ['id' => 'te3', 'from' => 't-loopless', 'to' => 't-done', 'handle' => 'default'],
            ],
        );

        $this->publish($template->flow_id);

        return $template->fresh();
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    private function templateDraft(array $nodes, array $edges): FlowDraft
    {
        return FlowDraft::factory()->template()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => $nodes,
            'edges'        => $edges,
        ]);
    }

    private function hostDraft(string $templateFlowId): FlowDraft
    {
        return FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'name'         => 'Host flow',
            'nodes'        => [
                $this->reference('ref', $templateFlowId),
                ['id' => 'h-after', 'type' => 'trace_test', 'version' => 1, 'config' => ['key' => 'host', 'value' => 'after']],
            ],
            'edges' => [
                ['id' => 'he1', 'from' => 'ref', 'to' => 'h-after', 'handle' => 'done'],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function reference(string $id, string $templateFlowId): array
    {
        return [
            'id'      => $id,
            'type'    => 'template',
            'version' => 1,
            'config'  => ['template_flow_id' => $templateFlowId, 'outputs' => [['name' => 'done', 'label' => 'Done']]],
        ];
    }

    private function publish(string $flowId): FlowDefinition
    {
        try {
            return $this->app->make(PublishFlowService::class)->execute($flowId);
        } catch (FlowValidationException $exception) {
            $this->fail('Publish refused: ' . json_encode(array_map(static fn ($error): string => "{$error->code}: {$error->message}", $exception->errors)));
        }
    }

    private function assertRefused(string $flowId, string $code): void
    {
        try {
            $this->app->make(PublishFlowService::class)->execute($flowId);
        } catch (FlowValidationException $exception) {
            $this->assertContains($code, array_map(static fn ($error): string => $error->code, $exception->errors));

            return;
        }

        $this->fail("Publishing {$flowId} should be refused with {$code}.");
    }
}

/**
 * Writes `flow.<key> = <value>`: a witness of which nodes ran.
 */
final class TraceTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'trace_test';
    }

    public function version(): int
    {
        return 1;
    }

    public function supportedVersions(): array
    {
        return [1];
    }

    public function label(): string
    {
        return 'Trace test';
    }

    public function category(): string
    {
        return 'Test';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config = $nodeConfig['config'] ?? [];

        return NodeExecutionResult::executed(stateChanges: ['flow.' . $config['key'] => $config['value']]);
    }
}
