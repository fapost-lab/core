<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Handlers\LoopNodeHandler;

/**
 * Finds a state path that one node writes as a single value and another treats as a group.
 *
 * `contact.profile` written by one node and `contact.profile.name` by another cannot both hold: at runtime
 * `ContactWriter` throws `StructuralPathConflictException` for the second write, and a session path is overwritten
 * silently. This checker reports the clash at design time and at publish, naming the path and the nodes, so the author
 * never meets the exception.
 *
 * Writes are read by {@see NodeWriteCollector} from every node that saves a value (`input`, `assign`, `send_message`,
 * `ask_person`, `call`, `subflow` output mapping, in the variable contract and the legacy shapes), plus the counter a `loop` keeps in
 * the session. Only writes are compared; a read never throws, so it is not a conflict. Session and contact paths are
 * separate namespaces.
 */
final readonly class VariablePathConflictChecker
{
    public const string CODE_CONFLICT = 'variable_path_conflict';

    public function __construct(private NodeWriteCollector $writes)
    {
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodeMap  the flow's nodes by id
     *
     * @return list<FlowValidationErrorDto>
     */
    public function check(array $nodeMap): array
    {
        /** @var array<string, array{segments: list<string>, nodes: list<string>}> $paths by "namespace.seg.seg" */
        $paths = [];

        foreach ($this->collectWrites($nodeMap) as $write) {
            $key = $write['namespace'] . '.' . implode('.', $write['segments']);

            $paths[$key]['segments'] = $write['segments'];
            $paths[$key]['nodes'][]  = $write['node_id'];
        }

        /** @var array<string, array<string, list<string>>> $groupsByLeaf leaf path => deeper path => node ids */
        $groupsByLeaf = [];

        foreach ($paths as $key => $path) {
            $namespace = mb_strstr($key, '.', true);

            for ($length = 1; $length < count($path['segments']); $length++) {
                $leafKey = $namespace . '.' . implode('.', array_slice($path['segments'], 0, $length));

                if (isset($paths[$leafKey])) {
                    $groupsByLeaf[$leafKey][$key] = $path['nodes'];
                }
            }
        }

        $errors = [];

        foreach ($groupsByLeaf as $leafKey => $groups) {
            $leafNodes  = array_values(array_unique($paths[$leafKey]['nodes']));
            $groupParts = [];

            foreach ($groups as $groupKey => $nodeIds) {
                $groupParts[] = (string) __('builder.validation.variable_path_conflict_group', [
                    'group' => $groupKey,
                    'nodes' => implode(', ', array_unique($nodeIds)),
                ]);
            }

            $errors[] = new FlowValidationErrorDto(
                path: "nodes.{$leafNodes[0]}",
                message: (string) __('builder.validation.variable_path_conflict', [
                    'path'   => $leafKey,
                    'nodes'  => implode(', ', $leafNodes),
                    'groups' => implode(', ', $groupParts),
                ]),
                code: self::CODE_CONFLICT,
            );
        }

        return $errors;
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodeMap
     *
     * @return list<array{namespace: string, segments: list<string>, node_id: string}>
     */
    private function collectWrites(array $nodeMap): array
    {
        $writes = [];

        foreach ($nodeMap as $nodeId => $node) {
            $id    = is_string($node['id'] ?? null) && '' !== $node['id'] ? $node['id'] : (string) $nodeId;
            $paths = $this->writes->paths($node);

            // A loop keeps its counter (and, when counted, its total) in the session.
            if (LoopNodeHandler::TYPE === ($node['type'] ?? null)) {
                $config   = is_array($node['config'] ?? null) ? $node['config'] : [];
                $iterator = LoopNodeHandler::iteratorNameOf($config);
                $paths[]  = 'flow.' . $iterator;

                if ('while' !== ($config['mode'] ?? null)) {
                    $paths[] = 'flow.' . $iterator . LoopNodeHandler::TOTAL_SUFFIX;
                }
            }

            foreach ($paths as $path) {
                $segments = explode('.', $path);
                $write    = ['namespace' => array_shift($segments), 'segments' => $segments, 'node_id' => $id];

                if ([] !== $segments) {
                    $writes[] = $write;
                }
            }
        }

        return $writes;
    }
}
