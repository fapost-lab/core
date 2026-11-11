<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Templates;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Audit\Models\ActivityEntry;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Services\LoadBuilderFlowService;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Flow\Templates\TemplateExpander;
use App\Domains\Flow\Templates\TemplateUsageRepository;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;
use Tests\Support\ComparesJsonColumns;

/**
 * Template parameters (task flow-template-params): a field of a template's node declared as a parameter is replaced
 * in the copy by the value a host sets on its reference node; an empty value keeps the template's own value; the
 * template's own publish checks the declarations, and refuses a version that drops a parameter a host still sets.
 */
final class FlowTemplateParametersTest extends FeatureTestCase
{
    use ComparesJsonColumns;

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

    public function test_a_host_value_replaces_the_field_in_the_copy_and_an_empty_one_keeps_the_template_value(): void
    {
        $template = $this->publishedTemplate();
        $host     = $this->hostDraft($template->flow_id, [
            'searching' => ['en' => 'Looking for a manager', 'uk' => 'Шукаємо менеджера'],
            'connected' => ['en' => '  ', 'uk' => ''],
        ]);

        $definition = $this->publish($host->flow_id);

        $searching = $this->copyOf($definition, 't-search');
        $connected = $this->copyOf($definition, 't-connected');

        $this->assertSameJson(['en' => 'Looking for a manager', 'uk' => 'Шукаємо менеджера'], $searching['config']['text']);
        $this->assertSameJson(['en' => 'Connected', 'uk' => "Під'єднано"], $connected['config']['text'], 'an empty value keeps the template text');
        $this->assertArrayNotHasKey('parameters', $searching, 'a copy never carries the declarations');

        $reference = collect($definition->nodes)->firstWhere('id', 'ref');
        $this->assertSameJson([
            ['key' => 'searching', 'label' => 'Searching text', 'node_type' => 'send_message', 'node_version' => 1, 'field' => 'text'],
            ['key' => 'connected', 'label' => null, 'node_type' => 'send_message', 'node_version' => 1, 'field' => 'text'],
        ], $reference['config']['parameters']);
    }

    public function test_a_language_the_host_left_blank_keeps_the_template_text_for_that_language(): void
    {
        $template = $this->publishedTemplate();
        $host     = $this->hostDraft($template->flow_id, [
            'searching' => ['en' => '', 'uk' => 'Шукаємо менеджера'],
            'connected' => ['en' => 'Manager connected', 'uk' => ' ', 'pl' => ''],
        ]);

        $definition = $this->publish($host->flow_id);

        $this->assertSameJson(
            ['en' => 'Searching…', 'uk' => 'Шукаємо менеджера'],
            $this->copyOf($definition, 't-search')['config']['text'],
            'a blank base language takes the template text, a set one stays',
        );
        $this->assertSameJson(
            ['en' => 'Manager connected', 'uk' => "Під'єднано"],
            $this->copyOf($definition, 't-connected')['config']['text'],
            'a blank language the template has no text for is dropped',
        );
        $this->assertSame('Fallback', TemplateExpander::withTemplateLanguages(['en' => ' '], 'Fallback')['en'], 'a plain template text fills every blank language');
    }

    public function test_false_and_zero_are_values_and_blank_ones_are_empty(): void
    {
        $this->assertFalse(TemplateExpander::isEmptyParameterValue(false));
        $this->assertFalse(TemplateExpander::isEmptyParameterValue(0));
        $this->assertFalse(TemplateExpander::isEmptyParameterValue(['en' => 'Hi', 'uk' => '']));
        $this->assertFalse(TemplateExpander::isEmptyParameterValue(['a']));
        $this->assertTrue(TemplateExpander::isEmptyParameterValue(null));
        $this->assertTrue(TemplateExpander::isEmptyParameterValue(' '));
        $this->assertTrue(TemplateExpander::isEmptyParameterValue([]));
        $this->assertTrue(TemplateExpander::isEmptyParameterValue(['en' => '', 'uk' => ' ']));
    }

    public function test_a_template_publish_refuses_a_bad_key_a_duplicate_key_and_a_field_that_cannot_be_a_parameter(): void
    {
        $template = $this->templateDraft([
            $this->message('t-a', 'A', [
                ['key' => 'Bad Key', 'label' => null, 'field' => 'text'],
                ['key' => 'buttons', 'label' => null, 'field' => 'buttons'],
                ['key' => 'missing', 'label' => null, 'field' => 'no_such_field'],
            ]),
            $this->message('t-b', 'B', [['key' => 'buttons', 'label' => null, 'field' => 'text']]),
            ['id' => 't-done', 'type' => 'template_output', 'version' => 1, 'config' => ['name' => 'done'], 'parameters' => [['key' => 'name', 'field' => 'name']]],
        ]);

        try {
            $this->app->make(PublishFlowService::class)->execute($template->flow_id);
            $this->fail('the publish should be refused');
        } catch (FlowValidationException $exception) {
            $errors = array_map(static fn ($error): string => "{$error->code}@{$error->path}", $exception->errors);
        }

        $this->assertContains('template_parameter_invalid_key@nodes.t-a.parameters.0.key', $errors);
        $this->assertContains('template_parameter_invalid_field@nodes.t-a.parameters.1.field', $errors);
        $this->assertContains('template_parameter_invalid_field@nodes.t-a.parameters.2.field', $errors);
        $this->assertContains('template_parameter_duplicate_key@nodes.t-b.parameters.0.key', $errors);
        $this->assertContains('template_parameter_invalid_field@nodes.t-done.parameters', $errors);
        $this->assertSame(0, FlowDefinition::query()->where('flow_id', $template->flow_id)->count());
    }

    public function test_a_template_publish_that_drops_a_parameter_a_host_sets_is_refused_with_the_host_listed(): void
    {
        $template = $this->publishedTemplate();
        $setter   = $this->hostDraft($template->flow_id, ['searching' => ['en' => 'Custom']]);
        $other    = $this->hostDraft($template->flow_id, ['searching' => ['en' => ''], 'connected' => null]);
        $this->publish($setter->flow_id);
        $this->publish($other->flow_id);

        $this->updateTemplateNode($template, 't-search', static function (array $node): array {
            unset($node['parameters']);

            return $node;
        });

        try {
            $this->app->make(PublishFlowService::class)->execute($template->flow_id);
            $this->fail('the publish should be refused');
        } catch (FlowValidationException $exception) {
            $this->assertSame(["template.hosts.{$setter->flow_id}"], array_values(array_unique(array_map(static fn ($error): string => $error->path, $exception->errors))));
            $this->assertSame('template_breaks_flow', $exception->errors[0]->code);
            $this->assertStringContainsString("no parameter 'searching'", $exception->errors[0]->message);
        }

        $this->assertSame(1, FlowDefinition::query()->where('flow_id', $template->flow_id)->max('version'), 'nothing is published');

        // Once the flow that set it is gone from the index, the leftover empty value of the other one breaks nothing.
        $this->app->make(TemplateUsageRepository::class)->forgetHost($setter->flow_id);
        $this->publish($template->flow_id);
        $this->assertSame(2, FlowDefinition::query()->where('flow_id', $other->flow_id)->max('version'));
    }

    public function test_a_template_text_change_reaches_hosts_without_a_value_and_hosts_with_one_keep_theirs(): void
    {
        $template = $this->publishedTemplate();
        $custom   = $this->hostDraft($template->flow_id, ['searching' => ['en' => 'Custom searching']]);
        $plain    = $this->hostDraft($template->flow_id, []);
        $this->publish($custom->flow_id);
        $this->publish($plain->flow_id);

        $this->updateTemplateNode($template, 't-search', static function (array $node): array {
            $node['config']['text'] = ['en' => 'Searching, v2'];

            return $node;
        });
        $this->publish($template->flow_id);
        // A second template publish collapses and re-expands the hosts again: the host's values survive it.
        $this->publish($template->flow_id);

        $customActive = FlowDefinition::query()->where('flow_id', $custom->flow_id)->active()->sole();
        $plainActive  = FlowDefinition::query()->where('flow_id', $plain->flow_id)->active()->sole();

        $this->assertSame(3, $customActive->version);
        $this->assertSameJson(['en' => 'Custom searching'], $this->copyOf($customActive, 't-search')['config']['text']);
        $this->assertSameJson(['en' => 'Custom searching'], collect($customActive->nodes)->firstWhere('id', 'ref')['config']['parameter_values']['searching']);
        $this->assertSameJson(['en' => 'Searching, v2'], $this->copyOf($plainActive, 't-search')['config']['text']);
    }

    public function test_the_builder_sees_parameters_and_its_validation_reports_a_value_for_a_parameter_the_template_lacks(): void
    {
        $template = $this->publishedTemplate();
        $host     = $this->hostDraft($template->flow_id, []);

        $available = $this->app->make(LoadBuilderFlowService::class)->execute($host->flow_id)->availableTemplates;
        $this->assertSameJson([
            ['key' => 'searching', 'label' => 'Searching text', 'node_type' => 'send_message', 'node_version' => 1, 'field' => 'text', 'default' => ['en' => 'Searching…']],
            ['key' => 'connected', 'label' => null, 'node_type' => 'send_message', 'node_version' => 1, 'field' => 'text', 'default' => ['en' => 'Connected', 'uk' => "Під'єднано"]],
        ], $available[0]['parameters']);

        $reference                                      = $this->reference($template->flow_id, []);
        $reference['config']['parameter_values']['old'] = 'set';
        $errors                                         = $this->app->make(TemplateExpander::class)->draftErrors(
            nodes: [$reference],
            edges: [],
            assistantId: (string) $this->assistant->getKey(),
            isTemplate: false,
        );

        $this->assertSame(['template_unknown_parameter'], array_map(static fn ($error): string => $error->code, $errors));
    }

    public function test_the_activity_log_never_records_a_parameter_value(): void
    {
        $template = $this->publishedTemplate();
        $host     = $this->hostDraft($template->flow_id, ['searching' => ['en' => 'Secret wording']]);

        $this->publish($host->flow_id);
        $this->publish($template->flow_id);

        $logged = (string) json_encode(ActivityEntry::query()->get()->map->getAttributes());
        // The template twice, the host by its own publish and by the template's republish.
        $this->assertSame(4, ActivityEntry::query()->where('description', 'flow.published')->count());
        $this->assertStringNotContainsString('Secret wording', $logged);
    }

    private function publishedTemplate(): FlowDraft
    {
        $searching = $this->message('t-search', ['en' => 'Searching…'], [['key' => 'searching', 'label' => 'Searching text', 'field' => 'text']]);
        $connected = $this->message('t-connected', ['en' => 'Connected', 'uk' => "Під'єднано"], [['key' => 'connected', 'field' => 'text']]);
        $template  = $this->templateDraft([$searching, $connected, ['id' => 't-done', 'type' => 'template_output', 'version' => 1, 'config' => ['name' => 'done']]]);

        $this->publish($template->flow_id);

        return $template->fresh();
    }

    /**
     * @param  list<array<string, mixed>>  $nodes  in order; each leads to the next
     */
    private function templateDraft(array $nodes): FlowDraft
    {
        $edges = [];

        for ($i = 0; $i < count($nodes) - 1; ++$i) {
            $edges[] = ['id' => "te{$i}", 'from' => $nodes[$i]['id'], 'to' => $nodes[$i + 1]['id'], 'handle' => 'default'];
        }

        return FlowDraft::factory()->template()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => $nodes,
            'edges'        => $edges,
        ]);
    }

    /**
     * @param  string|array<string, string>  $text
     * @param  list<array<string, mixed>>     $parameters
     *
     * @return array<string, mixed>
     */
    private function message(string $id, string|array $text, array $parameters): array
    {
        return [
            'id'         => $id,
            'type'       => 'send_message',
            'version'    => 1,
            'config'     => ['content_type' => 'text', 'text' => $text],
            'parameters' => $parameters,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function hostDraft(string $templateFlowId, array $values): FlowDraft
    {
        return FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'name'         => 'Host flow',
            'nodes'        => [
                $this->reference($templateFlowId, $values),
                ['id' => 'h-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges' => [
                ['id' => 'he1', 'from' => 'ref', 'to' => 'h-end', 'handle' => 'done'],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @return array<string, mixed>
     */
    private function reference(string $templateFlowId, array $values): array
    {
        $config = ['template_flow_id' => $templateFlowId, 'outputs' => [['name' => 'done', 'label' => null]]];

        if ([] !== $values) {
            $config['parameter_values'] = $values;
        }

        return ['id' => 'ref', 'type' => 'template', 'version' => 1, 'config' => $config];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function updateTemplateNode(FlowDraft $template, string $nodeId, callable $change): void
    {
        $template->update(['nodes' => collect($template->nodes)
            ->map(static fn (array $node): array => $nodeId === $node['id'] ? $change($node) : $node)
            ->all()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function copyOf(FlowDefinition $definition, string $templateNodeId): array
    {
        $copy = collect($definition->nodes)->firstWhere('origin.node_id', $templateNodeId);
        $this->assertIsArray($copy, "the copy of {$templateNodeId}");

        return $copy;
    }

    private function publish(string $flowId): FlowDefinition
    {
        try {
            return $this->app->make(PublishFlowService::class)->execute($flowId);
        } catch (FlowValidationException $exception) {
            $this->fail('Publish refused: ' . json_encode(array_map(static fn ($error): string => "{$error->code}@{$error->path}: {$error->message}", $exception->errors)));
        }
    }
}
