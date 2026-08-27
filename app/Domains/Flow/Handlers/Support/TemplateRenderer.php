<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers\Support;

use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\Flow\DTO\ExpressionContext;

/**
 * Per-node template substitution facade for handlers.
 *
 * Prefers the runtime-resolved {@see \FAPost\Foundation\Flow\Contracts\ExpressionEngineInterface}
 * carried on {@see NodeExecutionContext} — that engine reads through
 * {@see \FAPost\Foundation\Flow\Contracts\ScopedStateReaderInterface} and
 * therefore resolves {@code contact.*} / {@code module.*} placeholders, not
 * just the session-state namespaces ({@code flow.*}, {@code system.*},
 * {@code rag.*}, {@code call.*}).
 *
 * When neither engine nor reader are present (legacy unit tests building
 * NodeExecutionContext by hand), falls back to a literal {@code {{path}}}
 * substitution against the supplied session-state array. The fallback's
 * scope is strictly the session JSON.
 *
 * Recursive over arrays — same semantics as the previous TemplateResolver:
 * non-string leaves are returned as-is, missing paths render empty string.
 */
final class TemplateRenderer
{
    private const string PLACEHOLDER_PATTERN = '/\{\{\s*([\w.]+)\s*\}\}/';

    /**
     * @param  array<string, mixed>  $sessionState  used by the fallback path; ignored when the engine is present
     */
    public function render(mixed $value, NodeExecutionContext $context, array $sessionState): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $inner) {
                $out[$key] = $this->render($inner, $context, $sessionState);
            }

            return $out;
        }

        if ( ! is_string($value) || ! str_contains($value, '{{')) {
            return $value;
        }

        $engine = $context->expressionEngine;
        $reader = $context->stateReader;

        if (null !== $engine && null !== $reader) {
            return $engine->evaluate(
                $value,
                new ExpressionContext(
                    tenantId: $context->tenantId,
                    contactId: $context->contactId,
                    sessionId: $context->sessionId,
                    stateReader: $reader,
                ),
            );
        }

        // Fallback: legacy session-state-only resolution. Used by unit tests
        // that build NodeExecutionContext without engine wiring.
        return (string) preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            static function (array $match) use ($sessionState): string {
                $path     = (string) ($match[1] ?? '');
                $resolved = data_get($sessionState, $path);

                return null === $resolved ? '' : (string) $resolved;
            },
            $value,
        );
    }
}
