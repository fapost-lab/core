<?php

declare(strict_types=1);

namespace Tests\Fixtures\Solution\Commands;

use Fapost\Foundation\Flow\Commands\CommandActionTypeInterface;
use Fapost\Foundation\Flow\Commands\CommandContext;
use Fapost\Foundation\Flow\Commands\CommandOutcome;
use Fapost\Foundation\Flow\DTO\NodeConfigIssue;
use Tests\Fixtures\Solution\FeedbackCalls;

/**
 * The `feedback.thank` command action type of the fixture Solution: it replies with the configured `text` (required;
 * longer than {@see LONG_TEXT} characters is a warning) and, when a test binds {@see FeedbackCalls}, records the call there with the tenant it ran for.
 */
final class FeedbackThankCommandType implements CommandActionTypeInterface
{
    final public const string TYPE = 'feedback.thank';

    final public const int LONG_TEXT = 160;

    public function __construct(
        private readonly ?FeedbackCalls $calls = null,
    ) {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return 'Thank the contact';
    }

    public function configSchema(): array
    {
        return ['text' => ['type' => 'string', 'label' => 'Reply']];
    }

    public function validateConfig(array $config): array
    {
        if (! is_string($config['text'] ?? null) || '' === $config['text']) {
            return [NodeConfigIssue::error('missing_text', 'The reply text is required.', 'text')];
        }

        if (mb_strlen($config['text']) > self::LONG_TEXT) {
            return [NodeConfigIssue::warning('long_text', 'A long reply may be cut by the channel.', 'text')];
        }

        return [];
    }

    public function handle(CommandContext $context, array $config): CommandOutcome
    {
        if (null !== $this->calls) {
            $this->calls->calls[] = ['action' => self::TYPE, 'tenant_id' => $context->tenantId, 'parameters' => ['command' => $context->command]];
        }

        return CommandOutcome::reply(is_string($config['text'] ?? null) ? $config['text'] : null);
    }
}
