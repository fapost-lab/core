<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

use Fapost\Foundation\Flow\DTO\NodeConfigIssue;
use InvalidArgumentException;

/**
 * The config of one command of a Solution's action type was refused by the type's own `validateConfig()`.
 *
 * An {@see InvalidArgumentException} like every other refusal of {@see AssistantCommandsValidator}, so callers that
 * catch those keep working; the console reads {@see $position} and {@see $issues} to show each error under its field.
 */
final class InvalidCommandConfigException extends InvalidArgumentException
{
    /**
     * @param  int|string  $position  the key of the command in the list the validator was given
     * @param  list<NodeConfigIssue>  $issues  the errors of the config, never empty
     */
    public function __construct(
        string $message,
        public readonly int|string $position,
        public readonly array $issues,
    ) {
        parent::__construct($message);
    }
}
