<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services\Resolvers;

use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Models\FlowTrigger;
use FAPost\Foundation\Flow\Contracts\TriggerTypeResolverInterface;
use FAPost\Foundation\Flow\DTO\ResolvedTrigger;
use FAPost\Foundation\Flow\DTO\TriggerContext;

final readonly class MessageTriggerResolver implements TriggerTypeResolverInterface
{
    public function __construct(
        private FlowTriggerRepositoryInterface $triggers,
    ) {
    }

    public function resolve(TriggerContext $context): ?ResolvedTrigger
    {
        $messageText = $context->payload['text'] ?? null;

        if ( ! is_string($messageText) || '' === mb_trim($messageText)) {
            return null;
        }

        $triggers = $this->triggers->getActiveByType(
            tenantId: $context->tenantId,
            assistantId: $context->assistantId,
            type: FlowTriggerType::Message->value,
        );

        foreach ($triggers as $trigger) {
            if ($this->matchesTextTrigger($trigger, $messageText)) {
                return new ResolvedTrigger(
                    triggerId: (string)$trigger->getKey(),
                    flowId: $trigger->flow_id,
                    type: $trigger->type->value,
                    config: $trigger->config,
                    metadata: [
                        'tenant_id'    => $context->tenantId,
                        'assistant_id' => $context->assistantId,
                    ],
                );
            }
        }

        return null;
    }

    private function matchesTextTrigger(FlowTrigger $trigger, string $messageText): bool
    {
        $keywords = $trigger->config['keywords'] ?? null;
        $match    = $trigger->config['match'] ?? null;

        if ( ! is_array($keywords) || ! is_string($match)) {
            return false;
        }

        $normalizedText = mb_strtolower(mb_trim($messageText));
        $rawText        = mb_trim($messageText);

        foreach ($keywords as $keyword) {
            if ( ! is_string($keyword) || '' === mb_trim($keyword)) {
                continue;
            }

            if ($this->matchesByMode($match, $normalizedText, $rawText, mb_trim($keyword))) {
                return true;
            }
        }

        return false;
    }

    private function matchesByMode(string $match, string $normalizedText, string $rawText, string $keyword): bool
    {
        return match ($match) {
            'exact'    => $normalizedText === mb_strtolower($keyword),
            'contains' => str_contains($normalizedText, mb_strtolower($keyword)),
            'regex'    => 1 === preg_match($keyword, $rawText),
            default    => false,
        };
    }
}
