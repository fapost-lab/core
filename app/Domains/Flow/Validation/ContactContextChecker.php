<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Contracts\NamesOwnRecipientInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Enums\AssignTarget;
use App\Domains\Flow\Expression\Engines\TemplateEngine;
use App\Domains\Flow\Expression\ExpressionEngineRegistry;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\State\Variables\VariableStorage;
use Fapost\Foundation\Flow\Contracts\DeclaresContextRequirementInterface;
use Fapost\Foundation\Flow\Contracts\ExpressionEngineInterface;
use Fapost\Foundation\Flow\Enums\NodeContextRequirement;

/**
 * Refuses a node that needs a contact in a flow that one of its triggers starts without one.
 *
 * A flow's run has a contact unless the trigger that started it gives none ({@see TriggerContactContext::givesContact()}:
 * by its type, and for an event trigger by its event — a Core event gives none); a
 * flow without a trigger (a default flow, a global command's flow, a subflow) is started for a contact. So a flow is
 * contactless when any of its triggers, active or not, gives none — switching a trigger on does not publish the flow
 * again. In such a flow, a node needs a contact when:
 *
 * - its handler needs one: it declares {@see NodeContextRequirement::Contact}, or declares nothing
 *   ({@see DeclaresContextRequirementInterface}, Foundation's, so a Solution's node can declare too) — unless the
 *   node's config names its own recipient ({@see NamesOwnRecipientInterface}, a `send_message` addressee);
 * - its config reads `contact.*` or `module.*` — a reference of the flow's expression engine (a `{{…}}` placeholder
 *   of the `template` engine), a bare state path, or a `source` operand (a data accessor is asked for a contact, so
 *   `module.*` cannot be read without one either);
 * - its config reads or writes a contact variable (`storage: contact`), or is a legacy `assign` to the contact.
 *
 * Each such node gets one error, at `nodes.<id>`, naming the node, the reason and the triggers. Unregistered node types
 * are reported by the other checks and skipped here. The registry builds a handler per resolve (ADR-0001), so the
 * checker keeps no handler between calls.
 */
final readonly class ContactContextChecker
{
    public const string CODE = 'node_needs_contact';

    /** A placeholder or a `source` operand naming the contact or a module, bare or with a path. */
    private const string CONTACT_PATH_PATTERN = '/^(contact|module)(\.\w+)*$/';

    /** A bare state path in config; a lone word `contact` is plain text there, not a read. */
    private const string BARE_CONTACT_PATH_PATTERN = '/^(contact|module)(\.\w+)+$/';

    public function __construct(
        private NodeHandlerRegistryInterface $registry,
        private FlowTriggerRepositoryInterface $triggers,
        private ExpressionEngineRegistry $expressionEngines,
    ) {
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodeMap  the flow's nodes by id, annotations removed
     * @param  list<mixed>|null                     $triggers the flow's trigger list (the builder's, or the one being
     *                                                        published); null reads the live triggers of {@see $flowId}
     * @param  string|null                          $engineId the flow's expression engine, whose references are read
     *                                                        from config strings; null means `template`. An engine the
     *                                                        registry does not know reads none: the flow is refused
     *                                                        for it with `expression_engine_unknown` anyway
     *
     * @return list<FlowValidationErrorDto>
     */
    public function check(array $nodeMap, ?array $triggers, ?string $flowId, ?string $engineId = null): array
    {
        $contactless = $this->contactlessTriggerTypes($triggers, $flowId);

        if ([] === $contactless) {
            return [];
        }

        $triggerNames = implode(', ', $contactless);
        $triggerNoun  = 1 === count($contactless) ? 'trigger starts' : 'triggers start';

        $engineId ??= TemplateEngine::ID;
        $engine = $this->expressionEngines->has($engineId) ? $this->expressionEngines->get($engineId) : null;
        $errors = [];

        foreach ($nodeMap as $nodeId => $node) {
            $reason = $this->contactNeed($node, $engine);

            if (null === $reason) {
                continue;
            }

            $type     = is_string($node['type'] ?? null) ? $node['type'] : '';
            $errors[] = new FlowValidationErrorDto(
                path: "nodes.{$nodeId}",
                code: self::CODE,
                message: "Node '{$nodeId}' ({$type}) needs a contact: {$reason}. "
                    . "The flow's {$triggerNames} {$triggerNoun} it without one.",
            );
        }

        return $errors;
    }

    /**
     * The types of the flow's triggers that start it without a contact, each once, in list order; empty when every
     * run of the flow has one.
     *
     * @param  list<mixed>|null  $triggers
     *
     * @return list<string>
     */
    private function contactlessTriggerTypes(?array $triggers, ?string $flowId): array
    {
        if (null === $triggers) {
            if (null === $flowId) {
                return [];
            }

            $triggers = array_map(
                static fn (FlowTrigger $row): array => ['type' => $row->typeName(), 'config' => $row->config],
                $this->triggers->getByFlowId($flowId),
            );
        }

        $types = [];

        foreach ($triggers as $trigger) {
            $raw  = is_array($trigger) ? ($trigger['type'] ?? null) : null;
            $type = is_string($raw) && '' !== $raw ? $raw : null;

            $config = is_array($trigger) && is_array($trigger['config'] ?? null) ? $trigger['config'] : [];

            if (null !== $type && ! TriggerContactContext::givesContact($type, $config) && ! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Why the node needs a contact, or null when it does not.
     *
     * @param  array<string, mixed>  $node
     */
    private function contactNeed(array $node, ?ExpressionEngineInterface $engine): ?string
    {
        $type    = $node['type'] ?? null;
        $raw     = $node['version'] ?? 1;
        $version = is_int($raw) ? $raw : (is_numeric($raw) ? (int) $raw : 1);

        if (! is_string($type) || ! $this->registry->has($type, $version)) {
            return null;
        }

        $handler     = $this->registry->resolve($type, $version);
        $requirement = $handler instanceof DeclaresContextRequirementInterface
            ? $handler->contextRequirement()
            : NodeContextRequirement::Contact;

        $config = is_array($node['config'] ?? null) ? $node['config'] : [];

        if (NodeContextRequirement::Contact === $requirement
            && ! ($handler instanceof NamesOwnRecipientInterface && $handler->namesRecipient($config))) {
            return "the node type '{$type}' reaches the contact of the run";
        }

        return $this->configNeed($config, $engine);
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private function configNeed(array $config, ?ExpressionEngineInterface $engine): ?string
    {
        $variableName = $config['name'] ?? null;

        if (VariableStorage::Contact->value === ($config['storage'] ?? null) && is_string($variableName)) {
            return "it uses the contact variable '{$variableName}'";
        }

        if (AssignTarget::Contact->value === ($config['target'] ?? null) && array_key_exists('key', $config)) {
            return 'it writes to the contact';
        }

        $source = $config['source'] ?? null;

        if ('source' === ($config['ref'] ?? null) && is_string($source)
            && 1 === preg_match(self::CONTACT_PATH_PATTERN, $source)) {
            return "it reads '{$source}'";
        }

        foreach ($config as $value) {
            $need = match (true) {
                is_array($value)  => $this->configNeed($value, $engine),
                is_string($value) => $this->stringNeed($value, $engine),
                default           => null,
            };

            if (null !== $need) {
                return $need;
            }
        }

        return null;
    }

    private function stringNeed(string $value, ?ExpressionEngineInterface $engine): ?string
    {
        if (1 === preg_match(self::BARE_CONTACT_PATH_PATTERN, $value)) {
            return "it reads '{$value}'";
        }

        foreach ($engine?->extractReferences($value) ?? [] as $path) {
            if (1 === preg_match(self::CONTACT_PATH_PATTERN, $path)) {
                return "it reads '{$path}'";
            }
        }

        return null;
    }
}
