<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

use App\Domains\Flow\Contracts\DeclaresContextRequirementInterface;
use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Enums\AssignTarget;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Enums\NodeContextRequirement;
use App\Domains\Flow\Expression\Engines\TemplateEngine;
use App\Domains\Flow\Expression\ExpressionEngineRegistry;
use App\Domains\Flow\State\Variables\VariableStorage;
use Fapost\Foundation\Flow\Contracts\ExpressionEngineInterface;

/**
 * Refuses a node that needs a contact in a flow whose trigger starts it without one.
 *
 * A flow's run has a contact unless its trigger gives none ({@see FlowTriggerType::givesContact()}); a flow without a
 * trigger (a default flow, a global command's flow, a subflow) is started for a contact. In a flow whose trigger gives
 * none, a node needs a contact when:
 *
 * - its handler needs one: it declares {@see NodeContextRequirement::Contact}, or declares nothing
 *   ({@see DeclaresContextRequirementInterface}), which is every Solution node today;
 * - its config reads `contact.*` or `module.*` — a `{{…}}` placeholder, a bare state path, or a `source` operand
 *   (a data accessor is asked for a contact, so `module.*` cannot be read without one either);
 * - its config reads or writes a contact variable (`storage: contact`), or is a legacy `assign` to the contact.
 *
 * Each such node gets one error, at `nodes.<id>`, naming the node, the reason and the trigger. Unregistered node types
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
     * @param  array<string, mixed>|null            $trigger  the trigger as the builder sends it; null keeps the
     *                                                        stored one of {@see $flowId}
     *
     * @return list<FlowValidationErrorDto>
     */
    public function check(array $nodeMap, ?array $trigger, ?string $flowId): array
    {
        $contactless = $this->contactlessTrigger($trigger, $flowId);

        if (null === $contactless) {
            return [];
        }

        $errors = [];

        foreach ($nodeMap as $nodeId => $node) {
            $reason = $this->contactNeed($node);

            if (null === $reason) {
                continue;
            }

            $type     = is_string($node['type'] ?? null) ? $node['type'] : '';
            $errors[] = new FlowValidationErrorDto(
                path: "nodes.{$nodeId}",
                code: self::CODE,
                message: "Node '{$nodeId}' ({$type}) needs a contact: {$reason}. "
                    . "The flow's {$contactless->value} trigger starts it without one.",
            );
        }

        return $errors;
    }

    /**
     * The trigger type that starts this flow without a contact, or null when every run of it has one.
     *
     * @param  array<string, mixed>|null  $trigger
     */
    private function contactlessTrigger(?array $trigger, ?string $flowId): ?FlowTriggerType
    {
        if (null !== $trigger) {
            if (true === ($trigger['_delete'] ?? false)) {
                return null;
            }

            $type = is_string($trigger['type'] ?? null) ? FlowTriggerType::tryFrom($trigger['type']) : null;

            return null !== $type && ! $type->givesContact() ? $type : null;
        }

        if (null === $flowId) {
            return null;
        }

        // An inactive trigger counts too: switching it on does not publish the flow again.
        $type = $this->triggers->findByFlowId($flowId)?->type;

        return null !== $type && ! $type->givesContact() ? $type : null;
    }

    /**
     * Why the node needs a contact, or null when it does not.
     *
     * @param  array<string, mixed>  $node
     */
    private function contactNeed(array $node): ?string
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

        if (NodeContextRequirement::Contact === $requirement) {
            return "the node type '{$type}' reaches the contact of the run";
        }

        $config = $node['config'] ?? null;

        return is_array($config) ? $this->configNeed($config, $this->expressionEngines->get(TemplateEngine::ID)) : null;
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private function configNeed(array $config, ExpressionEngineInterface $engine): ?string
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

    private function stringNeed(string $value, ExpressionEngineInterface $engine): ?string
    {
        if (1 === preg_match(self::BARE_CONTACT_PATH_PATTERN, $value)) {
            return "it reads '{$value}'";
        }

        foreach ($engine->extractReferences($value) as $path) {
            if (1 === preg_match(self::CONTACT_PATH_PATTERN, $path)) {
                return "it reads '{$path}'";
            }
        }

        return null;
    }
}
