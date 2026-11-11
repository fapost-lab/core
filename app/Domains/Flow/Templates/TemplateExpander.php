<?php

declare(strict_types=1);

namespace App\Domains\Flow\Templates;

use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Enums\FlowKind;
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
 *    back to its draft shape ({@see collapse()}) when a template publish republishes the host.
 */
final readonly class TemplateExpander
{
    /** Config keys the expansion adds to a reference node; the draft never has them. */
    public const string CONFIG_DEFINITION_ID = 'template_definition_id';

    public const string CONFIG_VERSION = 'template_version';

    public const string CONFIG_EXITS = 'exits';

    /** Node key recording where a copied node came from. */
    public const string ORIGIN = 'origin';
    /** Namespace of the copied ids. Changing it re-issues every id of every copy on the next publish. */
    private const string ID_NAMESPACE = '6f1c3a52-8f0e-5b7d-9c4e-2a9d1b7e3f60';

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
     * template inside a template.
     *
     * @param  array<int, mixed>  $nodes  annotations already stripped
     * @param  array<int, mixed>  $edges
     *
     * @return list<FlowValidationErrorDto>
     */
    public function validateTemplateGraph(array $nodes, array $edges): array
    {
        $errors = [];
        $ids    = [];

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
        $errors      = [];

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

            $copy               = $this->rewrite($templateNode, $idMap);
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
