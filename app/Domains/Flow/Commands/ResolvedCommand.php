<?php

declare(strict_types=1);

namespace App\Domains\Flow\Commands;

/**
 * Output of {@see CommandMatcher::match()}.
 *
 * Carries everything the executor (Phase D-3) needs to perform the action:
 * the resolved type, optional response text (for terminate_session and the
 * implicit ack of send_message), optional flow id (for start_flow), and the
 * origin tag (built-in vs tenant) for observability.
 */
final readonly class ResolvedCommand
{
    public function __construct(
        public string $command,
        public CommandActionType $type,
        public ?string $response = null,
        public ?string $flowId = null,
        public ?string $text = null,
        public string $origin = 'tenant',
    ) {
    }
}
