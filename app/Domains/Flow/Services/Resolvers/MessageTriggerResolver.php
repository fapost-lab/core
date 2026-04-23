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

        $normalizedText  = $this->normalizeText(mb_trim($messageText));
        $containsTrigger = null;

        foreach ($triggers as $trigger) {
            $matchType = $this->matchType($trigger, $normalizedText);

            if ('exact' === $matchType) {
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

            if ('contains' === $matchType && null === $containsTrigger) {
                $containsTrigger = $trigger;
            }
        }

        if ($containsTrigger instanceof FlowTrigger) {
            return new ResolvedTrigger(
                triggerId: (string)$containsTrigger->getKey(),
                flowId: $containsTrigger->flow_id,
                type: $containsTrigger->type->value,
                config: $containsTrigger->config,
                metadata: [
                    'tenant_id'    => $context->tenantId,
                    'assistant_id' => $context->assistantId,
                ],
            );
        }

        return null;
    }

    private function matchType(FlowTrigger $trigger, string $normalizedText): ?string
    {
        $keywords = $trigger->config['keywords'] ?? null;
        $phrases  = $trigger->config['phrases'] ?? null;

        if ( ! is_array($keywords) || (null !== $phrases && ! is_array($phrases))) {
            return null;
        }

        $needles = $this->normalizedNeedles($keywords, is_array($phrases) ? $phrases : []);

        foreach ($needles as $needle) {
            if ($normalizedText === $needle) {
                return 'exact';
            }
        }

        foreach ($needles as $needle) {
            if (str_contains($normalizedText, $needle)) {
                return 'contains';
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $keywords
     * @param  array<int, mixed>  $phrases
     * @return list<string>
     */
    private function normalizedNeedles(array $keywords, array $phrases): array
    {
        $needles = [];

        foreach ([...$phrases, ...$keywords] as $value) {
            if ( ! is_string($value) || '' === mb_trim($value)) {
                continue;
            }

            $needles[] = $this->normalizeText($value);
        }

        return array_values(array_unique($needles));
    }

    private function normalizeText(string $value): string
    {
        $normalized = mb_strtolower(mb_trim($value));
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? $normalized;

        return preg_replace('/\s+/u', ' ', mb_trim($normalized)) ?? mb_trim($normalized);
    }
}
