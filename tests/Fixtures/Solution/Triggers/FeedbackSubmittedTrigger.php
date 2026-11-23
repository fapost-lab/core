<?php

declare(strict_types=1);

namespace Tests\Fixtures\Solution\Triggers;

use Fapost\Foundation\Flow\Contracts\TriggerTypeInterface;
use Fapost\Foundation\Flow\DTO\NodeConfigIssue;

/**
 * The `feedback.submitted` trigger type of the fixture Solution: a flow starts when feedback is submitted with at
 * least the configured score (`min_score`, 1-5, default 1). The fire's payload carries `score` and `comment`.
 */
final class FeedbackSubmittedTrigger implements TriggerTypeInterface
{
    final public const string TYPE = 'feedback.submitted';

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return 'Feedback submitted';
    }

    public function configSchema(): array
    {
        return ['min_score' => ['type' => 'number', 'label' => 'Minimum score']];
    }

    public function validateConfig(array $config): array
    {
        $minScore = $config['min_score'] ?? 1;

        if (! is_int($minScore) || $minScore < 1 || $minScore > 5) {
            return [NodeConfigIssue::error('invalid_min_score', 'The minimum score is a whole number from 1 to 5.', 'min_score')];
        }

        return [];
    }

    public function matches(array $config, array $payload): bool
    {
        $minScore = is_int($config['min_score'] ?? null) ? $config['min_score'] : 1;

        return is_int($payload['score'] ?? null) && $payload['score'] >= $minScore;
    }

    public function payloadFields(): array
    {
        return ['score', 'comment'];
    }
}
