<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Services\VariableSchemaCollector;
use App\Domains\Flow\State\Variables\Variable;
use App\Domains\Flow\State\Variables\VariableStorage;
use App\Domains\Flow\State\Variables\VariableType;

/**
 * Checks the `max_size` a list variable declares (loop node spec 7.3).
 *
 * `max_size` is how many elements a contact list keeps before the oldest drops out. It is read by `ContactWriter` from
 * the variable schema registry, which publish fills from the `variable` block's `properties`. A value outside
 * {@see Variable::LIST_MIN_SIZE}..{@see Variable::LIST_MAX_SIZE} (or not a whole number) is an error at publish, so the writer never has to
 * guess; the writer still clamps what it reads, for rows written before this check existed.
 */
final readonly class ListSizeChecker
{
    public const string CODE_INVALID = 'list_max_size_invalid';

    public function __construct(private VariableSchemaCollector $collector)
    {
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodeMap  the flow's nodes by id
     *
     * @return list<FlowValidationErrorDto>
     */
    public function check(array $nodeMap): array
    {
        $errors = [];

        foreach ($this->collector->collect(array_values($nodeMap)) as $declaration) {
            $variable = $declaration['variable'];

            if (VariableType::Array !== $variable->type || ! array_key_exists('max_size', $variable->properties)) {
                continue;
            }

            $maxSize = $variable->properties['max_size'];

            if (is_int($maxSize) && $maxSize >= Variable::LIST_MIN_SIZE && $maxSize <= Variable::LIST_MAX_SIZE && VariableStorage::Contact === $variable->storage) {
                continue;
            }

            $errors[] = new FlowValidationErrorDto(
                path: "nodes.{$declaration['node_id']}",
                message: VariableStorage::Contact === $variable->storage
                    ? sprintf('List "%s": max_size must be a whole number from %d to %d.', $variable->name, Variable::LIST_MIN_SIZE, Variable::LIST_MAX_SIZE)
                    : sprintf('List "%s": max_size applies only to a list saved to the contact.', $variable->name),
                code: self::CODE_INVALID,
            );
        }

        return $errors;
    }
}
