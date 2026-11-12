<?php

declare(strict_types=1);

namespace App\Domains\Flow\Templates;

use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;

/**
 * Where a node of a published definition came from, for the screens that show node ids of running and finished
 * sessions (the session list and card, the session history, the flow log). A node copied from a template carries
 * `origin` ({@see TemplateExpander::ORIGIN}); its id is a UUIDv5 nobody recognises, so the screens show
 * "template X vN → node" instead.
 *
 * Memoises definitions, template names and session definitions for one request; built per resolve, never shared.
 */
final class TemplateNodeOrigins
{
    /** @var array<string, array<string, array{template_flow_id: string, template_version: int, node_id: string, node_type: string}>> */
    private array $originsByDefinition = [];

    /** @var array<string, string> */
    private array $templateNames = [];

    /** @var array<string, string|null> */
    private array $definitionBySession = [];

    /**
     * @return array{template: string, version: int, nodeId: string, nodeType: string}|null null for a node of the
     *                                                                                    flow itself, or an unknown one
     */
    public function describe(?string $definitionId, ?string $nodeId): ?array
    {
        if (null === $definitionId || '' === $definitionId || null === $nodeId || '' === $nodeId) {
            return null;
        }

        $origin = $this->originsOf($definitionId)[$nodeId] ?? null;

        if (null === $origin) {
            return null;
        }

        return [
            'template' => $this->templateName($origin['template_flow_id']),
            'version'  => $origin['template_version'],
            'nodeId'   => $origin['node_id'],
            'nodeType' => $origin['node_type'],
        ];
    }

    /**
     * Same as {@see describe()}, for a node of a session known only by its id (a flow log row).
     *
     * @return array{template: string, version: int, nodeId: string, nodeType: string}|null
     */
    public function describeForSession(string $sessionId, ?string $nodeId): ?array
    {
        return $this->describe($this->definitionOfSession($sessionId), $nodeId);
    }

    /**
     * Loads, for a list of sessions, everything {@see describeForSession()} reads: the sessions' definitions, the
     * template origins of those definitions and the names of the templates, in a fixed number of queries.
     *
     * @param  list<string>  $sessionIds
     */
    public function preloadSessions(array $sessionIds): void
    {
        $missing = array_values(array_diff(array_unique($sessionIds), array_keys($this->definitionBySession)));

        if ([] !== $missing) {
            $found = FlowSession::query()->whereKey($missing)->pluck('flow_definition_id', 'id');

            foreach ($missing as $sessionId) {
                $definitionId                          = $found[$sessionId] ?? null;
                $this->definitionBySession[$sessionId] = null === $definitionId ? null : (string) $definitionId;
            }
        }

        $definitionIds = array_values(array_unique(array_filter(
            array_map(fn (string $sessionId): ?string => $this->definitionBySession[$sessionId] ?? null, $sessionIds),
        )));

        $this->preloadDefinitions($definitionIds);
    }

    /**
     * @param  list<string>  $definitionIds
     */
    private function preloadDefinitions(array $definitionIds): void
    {
        $missing = array_values(array_diff($definitionIds, array_keys($this->originsByDefinition)));

        if ([] !== $missing) {
            $nodesById = FlowDefinition::query()->whereKey($missing)->pluck('nodes', 'id');

            foreach ($missing as $definitionId) {
                $this->originsByDefinition[$definitionId] = $this->originsFromNodes($nodesById[$definitionId] ?? null);
            }
        }

        $templateFlowIds = [];

        foreach ($definitionIds as $definitionId) {
            foreach ($this->originsByDefinition[$definitionId] ?? [] as $origin) {
                $templateFlowIds[$origin['template_flow_id']] = true;
            }
        }

        $this->preloadTemplateNames(array_keys($templateFlowIds));
    }

    /**
     * @param  list<string>  $templateFlowIds
     */
    private function preloadTemplateNames(array $templateFlowIds): void
    {
        $missing = array_values(array_diff($templateFlowIds, array_keys($this->templateNames)));

        if ([] === $missing) {
            return;
        }

        $names = FlowDraft::query()->whereIn('flow_id', $missing)->pluck('name', 'flow_id')->all();
        $rest  = array_values(array_diff($missing, array_keys($names)));

        if ([] !== $rest) {
            // Newest version last, so it wins when the map is built.
            $published = FlowDefinition::query()
                ->whereIn('flow_id', $rest)
                ->orderBy('version')
                ->pluck('name', 'flow_id')
                ->all();

            $names += $published;
        }

        foreach ($missing as $flowId) {
            $name                         = $names[$flowId] ?? null;
            $this->templateNames[$flowId] = is_string($name) && '' !== $name ? $name : $flowId;
        }
    }

    private function definitionOfSession(string $sessionId): ?string
    {
        if (! array_key_exists($sessionId, $this->definitionBySession)) {
            $this->preloadSessions([$sessionId]);
        }

        return $this->definitionBySession[$sessionId] ?? null;
    }

    /**
     * @return array<string, array{template_flow_id: string, template_version: int, node_id: string, node_type: string}>
     */
    private function originsOf(string $definitionId): array
    {
        if (isset($this->originsByDefinition[$definitionId])) {
            return $this->originsByDefinition[$definitionId];
        }

        $this->preloadDefinitions([$definitionId]);

        return $this->originsByDefinition[$definitionId];
    }

    /**
     * @return array<string, array{template_flow_id: string, template_version: int, node_id: string, node_type: string}>
     */
    private function originsFromNodes(mixed $nodes): array
    {
        $nodes   = is_string($nodes) ? json_decode($nodes, true) : $nodes;
        $origins = [];

        foreach (is_array($nodes) ? $nodes : [] as $node) {
            $origin = is_array($node) ? ($node[TemplateExpander::ORIGIN] ?? null) : null;

            if (! is_array($origin) || ! is_string($node['id'] ?? null)) {
                continue;
            }

            $origins[$node['id']] = [
                'template_flow_id' => (string) ($origin['template_flow_id'] ?? ''),
                'template_version' => (int) ($origin['template_version'] ?? 0),
                'node_id'          => (string) ($origin['node_id'] ?? ''),
                'node_type'        => is_string($node['type'] ?? null) ? $node['type'] : '',
            ];
        }

        return $origins;
    }

    private function templateName(string $templateFlowId): string
    {
        if (! isset($this->templateNames[$templateFlowId])) {
            $this->preloadTemplateNames([$templateFlowId]);
        }

        return $this->templateNames[$templateFlowId];
    }
}
