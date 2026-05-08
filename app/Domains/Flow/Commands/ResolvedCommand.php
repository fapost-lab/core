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
 *
 * For built-in commands the response is delivered as a translation key in
 * {@see self::$responseKey} — the executor resolves it through
 * {@see \App\Domains\Flow\Contracts\ContentTranslatorInterface} so tenant
 * overrides in `tenant_translations` take effect.
 */
final readonly class ResolvedCommand
{
    /**
     * @param  string|array<string, string>|null  $response  literal text or a `lang => text` locale map
     * @param  string|array<string, string>|null  $text      same shape as `$response` for send_message body
     */
    public function __construct(
        public string $command,
        public CommandActionType $type,
        public string|array|null $response = null,
        public ?string $flowId = null,
        public string|array|null $text = null,
        public string $origin = 'tenant',
        public ?string $responseKey = null,
    ) {
    }
}
