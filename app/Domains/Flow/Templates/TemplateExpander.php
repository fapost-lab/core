<?php

declare(strict_types=1);

namespace App\Domains\Flow\Templates;

use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Enums\FlowKind;
use App\Domains\Flow\Handlers\LoopEndNodeHandler;
use App\Domains\Flow\Handlers\LoopNodeHandler;
use App\Domains\Flow\Handlers\TemplateNodeHandler;
use App\Domains\Flow\Handlers\TemplateOutputNodeHandler;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Nodes\AnnotationNodeTypes;
use Ramsey\Uuid\Uuid;

/**
 * Expands flow template references into copies of the templates' active definitions, at publish time (ADR
 * `adr-20261111-flow-templates-expanded-at-publish`). The runtime never sees a template as such: it runs the
 * copied nodes in the host's own session, and the reference node and the output nodes are pass-throughs.
 *
 * For each `template` node R of a host graph (in its draft shape, where R's edges are named by the template's
 * outputs):
 *
 * 1. the template's active definition is copied, every id in it re-issued as `UUIDv5(R, old id)` — node ids, edge
 *    ids and every config value under an `id` key (buttons) — and every string value equal to an old id rewritten
 *    to the new one (edges, edge handles named by button ids, `loop_end.loop_node_id`). The id is a UUID: state keys
 *    are split on dots, and persistent buttons store their id in a `uuid` column. Being derived from R and the old
 *    id, it is the same in every version of the template, and two copies of one template never share an id;
 * 2. each copied node records its `origin` (R, the template, its version and the node's id in it);
 * 3. R keeps its incoming edges and gets an `enter` edge into the copy's entry node; every edge `R --name--> X`
 *    becomes `output' --default--> X` from each copy of an output with that name. R's config records the template
 *    definition and version it carries and its `exits` (name → target), so the published graph can be collapsed
 *    back to its draft shape ({@see collapse()}) when a template publish republishes the host;
 * 4. each parameter the host sets on R (`parameter_values`, by key) replaces, in the copy, the field the template
 *    declared it for (`parameters` on the template's node). This happens after the ids are re-issued, so the host's
 *    text never passes through the id map. An empty value keeps the template's own value; a non-empty value for a
 *    key the template no longer has is an error, as an edge from a vanished output is.
 */
final readonly class TemplateExpander
{
    /** Config keys the expansion adds to a reference node; the draft never has them. */
    public const string CONFIG_DEFINITION_ID = 'template_definition_id';

    public const string CONFIG_VERSION = 'template_version';

    public const string CONFIG_EXITS = 'exits';

    /** Node key recording where a copied node came from. */
    public const string ORIGIN = 'origin';

    /**
     * Node key, in a template, declaring which fields of that node are parameters: `[{key, label, field}]`. A copy
     * never carries it.
     */
    public const string NODE_PARAMETERS = 'parameters';

    /** Reference config key holding the snapshot of the template's parameters the builder renders. */
    public const string CONFIG_PARAMETERS = 'parameters';

    /** Reference config key holding the host's values, by parameter key. */
    public const string CONFIG_PARAMETER_VALUES = 'parameter_values';

    public const string PARAMETER_KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * Schema field types a parameter may expose. Object-shaped fields are left out: their items carry ids (buttons)
     * that the copy's edges hang off as handles, and a host value would not carry the copy's re-issued ids.
     */
    public const array EXPOSABLE_FIELD_TYPES = [
        'string', 'text', 'number', 'boolean', 'enum', 'enum-cards', 'duration', 'array', 'state-picker', 'flow-picker',
    ];

    /** Node types whose fields hold the copy's own wiring, so none of them may be a parameter. */
    public const array NON_PARAMETERIZABLE_NODE_TYPES = [
        TemplateNodeHandler::TYPE,
        TemplateOutputNodeHandler::TYPE,
        LoopNodeHandler::TYPE,
        LoopEndNodeHandler::TYPE,
    ];

    /** Namespace of the copied ids. Changing it re-issues every id of every copy on the next publish. */
    private const string ID_NAMESPACE = '6f1c3a52-8f0e-5b7d-9c4e-2a9d1b7e3f60';

    public function __construct(
        private NodeHandlerRegistryInterface $registry,
    ) {
    }

    /**
     * The snapshot of a template's parameters a reference node keeps for the builder: no node id and no value, so a
     * change of the template's texts never changes a host's draft.
     *
     * @param  list<array{key: string, label: string|null, node_id: string, node_type: string, node_version: int, field: string, default: mixed}>  $parameters
     *
     * @return list<array{key: string, label: string|null, node_type: string, node_version: int, field: string}>
     */
    public static function parameterSnapshot(array $parameters): array
    {
        return array_map(static fn (array $parameter): array => [
            'key'          => $parameter['key'],
            'label'        => $parameter['label'],
            'node_type'    => $parameter['node_type'],
            'node_version' => $parameter['node_version'],
            'field'        => $parameter['field'],
        ], $parameters);
    }

    /**
     * Whether a host's parameter value is empty, so the template's own value stays: null, a blank string, an empty
     * list, or a language map without a non-blank text. `false` and `0` are values.
     */
    public static function isEmptyParameterValue(mixed $value): bool
    {
        if (null === $value) {
            return true;
        }

        if (is_string($value)) {
            return '' === mb_trim($value);
        }

        if (! is_array($value)) {
            return false;
        }

        if ([] === $value) {
            return true;
        }

        if (array_is_list($value)) {
            return false;
        }

        foreach ($value as $text) {
            if (! is_string($text) || '' !== mb_trim($text)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A host's non-empty value as it goes into the copy. In a language map, a language the host left blank keeps the
     * template's text for that language (its map entry, or its plain text), so a contact never gets an empty message
     * where the template has one; a blank language the template has no text for is dropped and resolves as any
     * missing language does. Any other value is taken as it is.
     */
    public static function withTemplateLanguages(mixed $value, mixed $templateValue): mixed
    {
        if (! is_array($value) || array_is_list($value)) {
            return $value;
        }

        foreach ($value as $language => $text) {
            if (! is_string($text) || '' !== mb_trim($text)) {
                continue;
            }

            $fallback = is_array($templateValue) ? ($templateValue[$language] ?? null) : $templateValue;

            if (is_string($fallback) && '' !== mb_trim($fallback)) {
                $value[$language] = $fallback;
            } else {
                unset($value[$language]);
            }
        }

        return $value;
    }

    /**
     * Expands every reference node of a host graph whose drafts belong to `$assistantId`.
     *
     * @param  array<int, mixed>  $nodes  the host graph in its draft shape, annotations already stripped
     * @param  array<int, mixed>  $edges
     */
    public function expand(array $nodes, array $edges, string $assistantId): TemplateExpansion
    {
        $errors      = [];
        $templateIds = [];
        $outNodes    = [];
        $outEdges    = [];
        $refs        = [];

        foreach ($nodes as $node) {
            if (is_array($node) && TemplateOutputNodeHandler::TYPE === ($node['type'] ?? null)) {
                $errors[] = new FlowValidationErrorDto(
                    path: 'nodes.' . (is_string($node['id'] ?? null) ? $node['id'] : '?'),
                    code: 'template_output_outside_template',
                    message: 'A template output belongs in a template; a flow ends a branch with an end node or nothing at all.',
                );
            }

            if (is_array($node) && TemplateNodeHandler::TYPE === ($node['type'] ?? null) && is_string($node['id'] ?? null)) {
                $refs[$node['id']] = true;
            }

            $outNodes[] = $node;
        }

        if ([] === $refs) {
            return new TemplateExpansion($nodes, $edges, [], $errors);
        }

        // Edges leaving a reference are replaced by the copy's exits; everything else stays.
        $exitsByRef = [];

        foreach ($edges as $edge) {
            $from = is_array($edge) && is_string($edge['from'] ?? null) ? $edge['from'] : null;

            if (null !== $from && isset($refs[$from])) {
                $handle = is_string($edge['handle'] ?? null) ? $edge['handle'] : 'default';
                $to     = is_string($edge['to'] ?? null) ? $edge['to'] : null;

                if (null !== $to && '' !== $to) {
                    $exitsByRef[$from][$handle] = $to;
                }

                continue;
            }

            $outEdges[] = $edge;
        }

        foreach ($outNodes as $index => $node) {
            if (! is_array($node) || ! isset($refs[$node['id'] ?? null])) {
                continue;
            }

            $refId  = (string) $node['id'];
            $result = $this->expandReference($node, $exitsByRef[$refId] ?? [], $assistantId);

            if ([] !== $result['errors']) {
                array_push($errors, ...$result['errors']);

                continue;
            }

            $outNodes[$index] = $result['reference'];
            array_push($outNodes, ...$result['nodes']);
            array_push($outEdges, ...$result['edges']);
            $templateIds[] = $result['template_flow_id'];
        }

        return new TemplateExpansion(
            nodes: array_values($outNodes),
            edges: array_values($outEdges),
            templateFlowIds: array_values(array_unique($templateIds)),
            errors: $errors,
        );
    }

    /**
     * What publishing would refuse about templates in a builder draft, for the validate endpoint: a template's own
     * graph rules, or a flow's references (template exists, belongs to the assistant, is published, the edges use its
     * outputs). The copied nodes themselves are validated only at publish.
     *
     * @param  array<int|string, mixed>  $nodes
     * @param  array<int|string, mixed>  $edges
     *
     * @return list<FlowValidationErrorDto>
     */
    public function draftErrors(array $nodes, array $edges, string $assistantId, bool $isTemplate): array
    {
        [$nodes, $edges] = AnnotationNodeTypes::strip(array_values($nodes), array_values($edges));

        return $isTemplate
            ? $this->validateTemplateGraph($nodes, $edges)
            : $this->expand($nodes, $edges, $assistantId)->errors;
    }

    /**
     * Turns a published host graph back into its draft shape: drops the copied nodes and their edges and the `enter`
     * edges, and gives each reference node back its named edges from the recorded `exits`. Expanding the result
     * again yields the same graph when the templates have not changed.
     *
     * @param  array<int, mixed>  $nodes
     * @param  array<int, mixed>  $edges
     *
     * @return array{0: list<mixed>, 1: list<mixed>}
     */
    public function collapse(array $nodes, array $edges): array
    {
        $copies   = [];
        $refs     = [];
        $outNodes = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || ! is_string($node['id'] ?? null)) {
                $outNodes[] = $node;

                continue;
            }

            if (is_array($node[self::ORIGIN] ?? null)) {
                $copies[$node['id']] = true;

                continue;
            }

            if (TemplateNodeHandler::TYPE === ($node['type'] ?? null)) {
                $config = is_array($node['config'] ?? null) ? $node['config'] : [];
                $exits  = is_array($config[self::CONFIG_EXITS] ?? null) ? $config[self::CONFIG_EXITS] : [];
                unset($config[self::CONFIG_EXITS], $config[self::CONFIG_DEFINITION_ID], $config[self::CONFIG_VERSION]);
                $node['config']    = $config;
                $refs[$node['id']] = $exits;
            }

            $outNodes[] = $node;
        }

        $outEdges = [];

        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $from = $edge['from'] ?? null;
            $to   = $edge['to'] ?? null;

            if (isset($copies[$from]) || isset($copies[$to])) {
                continue;
            }

            if (isset($refs[$from]) && TemplateNodeHandler::HANDLE_ENTER === ($edge['handle'] ?? null)) {
                continue;
            }

            $outEdges[] = $edge;
        }

        foreach ($refs as $refId => $exits) {
            foreach ($exits as $handle => $target) {
                if (is_string($handle) && is_string($target) && '' !== $target) {
                    $outEdges[] = [
                        'id'     => $this->issueId((string) $refId, 'exit:' . $handle),
                        'from'   => (string) $refId,
                        'to'     => $target,
                        'handle' => $handle,
                    ];
                }
            }
        }

        return [$outNodes, $outEdges];
    }

    /**
     * Checks a template's own graph before it is published: one entry node, valid and unique-per-name outputs, no
     * template inside a template, and parameters with a valid key unique in the template on an exposable field.
     *
     * @param  array<int, mixed>  $nodes  annotations already stripped
     * @param  array<int, mixed>  $edges
     *
     * @return list<FlowValidationErrorDto>
     */
    public function validateTemplateGraph(array $nodes, array $edges): array
    {
        $errors        = [];
        $ids           = [];
        $parameterKeys = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || ! is_string($node['id'] ?? null)) {
                continue;
            }

            $ids[$node['id']] = true;
            $path             = "nodes.{$node['id']}";
            $type             = $node['type'] ?? null;

            if (TemplateNodeHandler::TYPE === $type) {
                $errors[] = new FlowValidationErrorDto(
                    path: $path,
                    code: 'template_nested',
                    message: 'A template cannot contain another template.',
                );
            }

            if (TemplateOutputNodeHandler::TYPE === $type) {
                $config = is_array($node['config'] ?? null) ? $node['config'] : [];
                $name   = $config[TemplateOutputNodeHandler::CONFIG_NAME] ?? null;

                if (! is_string($name)
                    || 1 !== preg_match(TemplateOutputNodeHandler::NAME_PATTERN, $name)
                    || TemplateNodeHandler::HANDLE_ENTER === $name) {
                    $errors[] = new FlowValidationErrorDto(
                        path: "{$path}.config.name",
                        code: 'template_output_invalid_name',
                        message: 'An output needs a name of lowercase latin letters, digits and _, starting with a letter (not "enter").',
                    );
                }
            }

            array_push($errors, ...$this->parameterDeclarationErrors($node, $path, $parameterKeys));
        }

        $withIncoming = [];

        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $from = $edge['from'] ?? null;
            $to   = $edge['to'] ?? null;

            if (is_string($to)) {
                $withIncoming[$to] = true;
            }

            if (is_string($from) && $this->isOutputNode($nodes, $from)) {
                $errors[] = new FlowValidationErrorDto(
                    path: "nodes.{$from}",
                    code: 'template_output_has_edges',
                    message: 'An output ends a branch of the template; the flow that uses the template decides what comes next.',
                );
            }
        }

        $entries = array_diff_key($ids, $withIncoming);

        if (1 !== count($entries)) {
            $errors[] = new FlowValidationErrorDto(
                path: 'template.entry',
                code: 'template_entry_not_single',
                message: 'A template must have exactly one starting node (a node nothing leads into); it has ' . count($entries) . '.',
            );
        }

        return $errors;
    }

    /**
     * The outputs of a published template, one per name, in the order they first appear.
     *
     * @return list<array{name: string, label: string|null}>
     */
    public function outputsOf(FlowDefinition $template): array
    {
        $outputs = [];

        foreach (is_array($template->nodes) ? $template->nodes : [] as $node) {
            if (! is_array($node) || TemplateOutputNodeHandler::TYPE !== ($node['type'] ?? null)) {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $name   = $config[TemplateOutputNodeHandler::CONFIG_NAME] ?? null;

            if (! is_string($name) || '' === $name || isset($outputs[$name])) {
                continue;
            }

            $label          = $config[TemplateOutputNodeHandler::CONFIG_LABEL] ?? null;
            $outputs[$name] = ['name' => $name, 'label' => is_string($label) && '' !== $label ? $label : null];
        }

        return array_values($outputs);
    }

    /**
     * The parameters of a published template, in the order of its nodes and then of each node's declarations. Each
     * names the node and field it replaces and carries the template's current value of that field (`default`); a
     * malformed declaration (its template publish refuses it) is skipped, and so is a key seen before.
     *
     * @return list<array{key: string, label: string|null, node_id: string, node_type: string, node_version: int, field: string, default: mixed}>
     */
    public function parametersOf(FlowDefinition $template): array
    {
        $parameters = [];

        foreach (is_array($template->nodes) ? $template->nodes : [] as $node) {
            if (! is_array($node) || ! is_string($node['id'] ?? null) || ! is_array($node[self::NODE_PARAMETERS] ?? null)) {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];

            foreach ($node[self::NODE_PARAMETERS] as $declaration) {
                $key   = is_array($declaration) ? ($declaration['key'] ?? null) : null;
                $field = is_array($declaration) ? ($declaration['field'] ?? null) : null;

                if (! is_string($key) || '' === $key || ! is_string($field) || '' === $field || isset($parameters[$key])) {
                    continue;
                }

                $label            = $declaration['label'] ?? null;
                $parameters[$key] = [
                    'key'          => $key,
                    'label'        => is_string($label) && '' !== $label ? $label : null,
                    'node_id'      => $node['id'],
                    'node_type'    => is_string($node['type'] ?? null) ? $node['type'] : '',
                    'node_version' => is_int($node['version'] ?? null) ? $node['version'] : 1,
                    'field'        => $field,
                    'default'      => $config[$field] ?? null,
                ];
            }
        }

        return array_values($parameters);
    }

    /**
     * The template flow ids a graph refers to, in its draft shape or published.
     *
     * @param  array<int, mixed>  $nodes
     *
     * @return list<string>
     */
    public function referencedTemplates(array $nodes): array
    {
        $ids = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || TemplateNodeHandler::TYPE !== ($node['type'] ?? null)) {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $id     = $config[TemplateNodeHandler::CONFIG_TEMPLATE_FLOW_ID] ?? null;

            if (is_string($id) && '' !== $id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>   $reference
     * @param  array<string, string>  $exits  handle → target node id, from the host's draft edges
     *
     * @return array{errors: list<FlowValidationErrorDto>, reference: array<string, mixed>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, template_flow_id: string}
     */
    private function expandReference(array $reference, array $exits, string $assistantId): array
    {
        $refId  = (string) $reference['id'];
        $path   = "nodes.{$refId}";
        $config = is_array($reference['config'] ?? null) ? $reference['config'] : [];
        $fail   = static fn (string $code, string $message): array => [
            'errors'           => [new FlowValidationErrorDto(path: $path, code: $code, message: $message)],
            'reference'        => $reference,
            'nodes'            => [],
            'edges'            => [],
            'template_flow_id' => '',
        ];

        $templateFlowId = $config[TemplateNodeHandler::CONFIG_TEMPLATE_FLOW_ID] ?? null;

        if (! is_string($templateFlowId) || '' === $templateFlowId) {
            return $fail('template_missing', 'Choose the template this node places.');
        }

        $draft = FlowDraft::query()->where('flow_id', $templateFlowId)->first();

        if (null === $draft || ! $draft->isTemplate()) {
            return $fail('template_not_found', "Template '{$templateFlowId}' does not exist.");
        }

        if ((string) $draft->assistant_id !== $assistantId) {
            return $fail('template_cross_assistant', "Template '{$draft->name}' belongs to another assistant; a flow can only use its own assistant's templates.");
        }

        $definition = FlowDefinition::query()
            ->where('flow_id', $templateFlowId)
            ->where('kind', FlowKind::Template->value)
            ->active()
            ->orderByDesc('version')
            ->first();

        if (null === $definition) {
            return $fail('template_not_published', "Template '{$draft->name}' has not been published yet.");
        }

        $templateNodes = is_array($definition->nodes) ? $definition->nodes : [];
        $templateEdges = is_array($definition->edges) ? $definition->edges : [];

        if ([] !== $this->referencedTemplates($templateNodes)) {
            return $fail('template_nested', "Template '{$draft->name}' contains another template, which is not supported.");
        }

        $outputNames = array_column($this->outputsOf($definition), 'name');
        $parameters  = $this->parametersOf($definition);
        $values      = is_array($config[self::CONFIG_PARAMETER_VALUES] ?? null) ? $config[self::CONFIG_PARAMETER_VALUES] : [];
        $errors      = [];

        foreach ($values as $key => $value) {
            if (! self::isEmptyParameterValue($value) && ! in_array((string) $key, array_column($parameters, 'key'), true)) {
                $errors[] = new FlowValidationErrorDto(
                    path: "{$path}.config.parameter_values.{$key}",
                    code: 'template_unknown_parameter',
                    message: "Template '{$draft->name}' v{$definition->version} has no parameter '{$key}', but this flow sets it.",
                );
            }
        }

        foreach (array_keys($exits) as $handle) {
            if (! in_array($handle, $outputNames, true)) {
                $errors[] = new FlowValidationErrorDto(
                    path: $path,
                    code: 'template_unknown_output',
                    message: "Template '{$draft->name}' v{$definition->version} has no output '{$handle}', but this flow continues from it.",
                );
            }
        }

        if ([] !== $errors) {
            return ['errors' => $errors, 'reference' => $reference, 'nodes' => [], 'edges' => [], 'template_flow_id' => ''];
        }

        $entryId = $this->entryNodeId($templateNodes, $templateEdges);

        if (null === $entryId) {
            return $fail('template_entry_not_single', "Template '{$draft->name}' v{$definition->version} has no single starting node.");
        }

        $idMap = $this->idMap($refId, $templateNodes, $templateEdges);

        $copiedNodes = [];

        foreach ($templateNodes as $templateNode) {
            if (! is_array($templateNode) || ! is_string($templateNode['id'] ?? null)) {
                continue;
            }

            $copy = $this->rewrite($templateNode, $idMap);
            unset($copy[self::NODE_PARAMETERS]);

            // After the id rewrite, so the host's values never pass through the id map.
            foreach ($parameters as $parameter) {
                $value = $values[$parameter['key']] ?? null;

                if ($parameter['node_id'] === $templateNode['id'] && ! self::isEmptyParameterValue($value)) {
                    $copy['config']                      = is_array($copy['config'] ?? null) ? $copy['config'] : [];
                    $copy['config'][$parameter['field']] = self::withTemplateLanguages($value, $copy['config'][$parameter['field']] ?? null);
                }
            }

            $copy[self::ORIGIN] = [
                'ref'              => $refId,
                'template_flow_id' => $templateFlowId,
                'template_version' => $definition->version,
                'node_id'          => $templateNode['id'],
            ];
            $copiedNodes[] = $copy;
        }

        $copiedEdges = [];

        foreach ($templateEdges as $templateEdge) {
            if (is_array($templateEdge)) {
                $copiedEdges[] = $this->rewrite($templateEdge, $idMap);
            }
        }

        $copiedEdges[] = [
            'id'     => $this->issueId($refId, 'enter'),
            'from'   => $refId,
            'to'     => $idMap[$entryId],
            'handle' => TemplateNodeHandler::HANDLE_ENTER,
        ];

        foreach ($copiedNodes as $copy) {
            if (TemplateOutputNodeHandler::TYPE !== ($copy['type'] ?? null)) {
                continue;
            }

            $name   = $copy['config'][TemplateOutputNodeHandler::CONFIG_NAME] ?? null;
            $target = is_string($name) ? ($exits[$name] ?? null) : null;

            if (null === $target) {
                continue;
            }

            $copiedEdges[] = [
                'id'     => $this->issueId($refId, 'exit:' . $copy['id']),
                'from'   => $copy['id'],
                'to'     => $target,
                'handle' => 'default',
            ];
        }

        $config[TemplateNodeHandler::CONFIG_OUTPUTS] = $this->outputsOf($definition);
        $config[self::CONFIG_PARAMETERS]             = self::parameterSnapshot($parameters);
        $config[self::CONFIG_DEFINITION_ID]          = (string) $definition->getKey();
        $config[self::CONFIG_VERSION]                = $definition->version;
        $config[self::CONFIG_EXITS]                  = $exits;
        $reference['config']                         = $config;

        return [
            'errors'           => [],
            'reference'        => $reference,
            'nodes'            => $copiedNodes,
            'edges'            => $copiedEdges,
            'template_flow_id' => $templateFlowId,
        ];
    }

    /**
     * Every id a copy re-issues: node ids, edge ids and config values under an `id` key, at any depth.
     *
     * @param  array<int, mixed>  $nodes
     * @param  array<int, mixed>  $edges
     *
     * @return array<string, string>
     */
    private function idMap(string $refId, array $nodes, array $edges): array
    {
        $map = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || ! is_string($node['id'] ?? null)) {
                continue;
            }

            $map[$node['id']] = $this->issueId($refId, $node['id']);

            if (is_array($node['config'] ?? null)) {
                $this->collectConfigIds($node['config'], $refId, $map);
            }
        }

        foreach ($edges as $edge) {
            if (is_array($edge) && is_string($edge['id'] ?? null) && '' !== $edge['id']) {
                $map[$edge['id']] = $this->issueId($refId, $edge['id']);
            }
        }

        return $map;
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @param  array<string, string>    $map
     */
    private function collectConfigIds(array $config, string $refId, array &$map): void
    {
        foreach ($config as $key => $value) {
            if ('id' === $key && is_string($value) && '' !== $value) {
                $map[$value] = $this->issueId($refId, $value);
            } elseif (is_array($value)) {
                $this->collectConfigIds($value, $refId, $map);
            }
        }
    }

    /**
     * Replaces every string value equal to an old id. Old ids are random tokens, so a whole-value match with
     * ordinary text is practically impossible.
     *
     * @param  array<array-key, mixed>  $value
     * @param  array<string, string>    $map
     *
     * @return array<array-key, mixed>
     */
    private function rewrite(array $value, array $map): array
    {
        foreach ($value as $key => $item) {
            if (is_string($item) && isset($map[$item])) {
                $value[$key] = $map[$item];
            } elseif (is_array($item)) {
                $value[$key] = $this->rewrite($item, $map);
            }
        }

        return $value;
    }

    /**
     * Checks the parameters a template's node declares: each a valid key unique in the template, naming a top-level
     * field of an exposable type that the node's schema declares.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, true>   $keys  keys declared so far in the template
     *
     * @return list<FlowValidationErrorDto>
     */
    private function parameterDeclarationErrors(array $node, string $path, array &$keys): array
    {
        if (! array_key_exists(self::NODE_PARAMETERS, $node) || null === $node[self::NODE_PARAMETERS] || [] === $node[self::NODE_PARAMETERS]) {
            return [];
        }

        $type    = $node['type'] ?? null;
        $version = $node['version'] ?? null;

        if (! is_array($node[self::NODE_PARAMETERS]) || in_array($type, self::NON_PARAMETERIZABLE_NODE_TYPES, true)) {
            return [new FlowValidationErrorDto(
                path: "{$path}.parameters",
                code: 'template_parameter_invalid_field',
                message: 'This node cannot have parameters.',
            )];
        }

        $schema = is_string($type) && is_int($version) && $this->registry->has($type, $version)
            ? $this->registry->resolve($type, $version)->configSchema()
            : [];
        $errors = [];

        foreach (array_values($node[self::NODE_PARAMETERS]) as $index => $declaration) {
            $key   = is_array($declaration) ? ($declaration['key'] ?? null) : null;
            $field = is_array($declaration) ? ($declaration['field'] ?? null) : null;
            $at    = "{$path}.parameters.{$index}";

            if (! is_string($key) || 1 !== preg_match(self::PARAMETER_KEY_PATTERN, $key)) {
                $errors[] = new FlowValidationErrorDto(
                    path: "{$at}.key",
                    code: 'template_parameter_invalid_key',
                    message: 'A parameter needs a key of lowercase latin letters, digits and _, starting with a letter.',
                );
            } elseif (isset($keys[$key])) {
                $errors[] = new FlowValidationErrorDto(
                    path: "{$at}.key",
                    code: 'template_parameter_duplicate_key',
                    message: "The template already has a parameter '{$key}'.",
                );
            } else {
                $keys[$key] = true;
            }

            $fieldSchema = is_string($field) && is_array($schema[$field] ?? null) ? $schema[$field] : null;

            if (null === $fieldSchema || ! in_array($fieldSchema['type'] ?? null, self::EXPOSABLE_FIELD_TYPES, true)) {
                $errors[] = new FlowValidationErrorDto(
                    path: "{$at}.field",
                    code: 'template_parameter_invalid_field',
                    message: 'A parameter must name a top-level setting of this node that holds text, a number, a choice or a toggle.',
                );
            }
        }

        return $errors;
    }

    private function issueId(string $refId, string $oldId): string
    {
        return Uuid::uuid5(self::ID_NAMESPACE, $refId . '/' . $oldId)->toString();
    }

    /**
     * @param  array<int, mixed>  $nodes
     */
    private function isOutputNode(array $nodes, string $nodeId): bool
    {
        foreach ($nodes as $node) {
            if (is_array($node) && $nodeId === ($node['id'] ?? null)) {
                return TemplateOutputNodeHandler::TYPE === ($node['type'] ?? null);
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $nodes
     * @param  array<int, mixed>  $edges
     */
    private function entryNodeId(array $nodes, array $edges): ?string
    {
        $withIncoming = [];

        foreach ($edges as $edge) {
            if (is_array($edge) && is_string($edge['to'] ?? null)) {
                $withIncoming[$edge['to']] = true;
            }
        }

        $entries = [];

        foreach ($nodes as $node) {
            if (is_array($node) && is_string($node['id'] ?? null) && ! isset($withIncoming[$node['id']])) {
                $entries[] = $node['id'];
            }
        }

        return 1 === count($entries) ? $entries[0] : null;
    }
}
