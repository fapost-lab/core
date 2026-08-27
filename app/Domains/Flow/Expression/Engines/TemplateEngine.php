<?php

declare(strict_types=1);

namespace App\Domains\Flow\Expression\Engines;

use Fapost\Foundation\Flow\Contracts\ExpressionEngineInterface;
use Fapost\Foundation\Flow\Contracts\ExpressionSyntaxException;
use Fapost\Foundation\Flow\DTO\ExpressionContext;

/**
 * Built-in V1 expression engine — pure {@code {{path}}} substitution.
 *
 * Supports only namespace-qualified path placeholders: {@code {{contact.name}}},
 * {@code {{flow.attempts}}}, {@code {{module.hr.department}}}, etc. No operators,
 * no functions, no arithmetic. This covers the majority of real flow authoring
 * needs (greeting messages, prompts, simple parameter assembly) without paying
 * the cost of a full expression DSL. More expressive engines (Symfony EL, Twig,
 * custom plugin DSLs) are added by registering additional implementations.
 *
 * Behaviour for unknown paths: substitutes empty string (graceful). Engines
 * that prefer fail-loud semantics should be implemented separately.
 */
final class TemplateEngine implements ExpressionEngineInterface
{
    public const string ID = 'template';

    private const string PLACEHOLDER_PATTERN = '/\{\{\s*([\w.]+)\s*\}\}/';

    private const string PATH_VALIDATION_PATTERN = '/^(contact|flow|system|rag|call|module)(\.[\w]+)+$/';

    public function id(): string
    {
        return self::ID;
    }

    public function version(): int
    {
        return 1;
    }

    public function evaluate(string $source, ExpressionContext $context): string
    {
        if (! str_contains($source, '{{')) {
            return $source;
        }

        return (string) preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            function (array $match) use ($context): string {
                $path = (string) ($match[1] ?? '');

                return $this->stringify($context->read($path));
            },
            $source
        );
    }

    public function validate(string $source): void
    {
        $count = preg_match_all(self::PLACEHOLDER_PATTERN, $source, $matches);

        if (false === $count || 0 === $count) {
            return;
        }

        foreach ($matches[1] as $path) {
            if (1 !== preg_match(self::PATH_VALIDATION_PATTERN, (string) $path)) {
                throw new ExpressionSyntaxException(sprintf(
                    "Invalid placeholder path '%s'. Must start with one of: contact, flow, system, rag, call, module — followed by at least one segment.",
                    $path,
                ));
            }
        }
    }

    /**
     * @return list<string>
     */
    public function extractReferences(string $source): array
    {
        $count = preg_match_all(self::PLACEHOLDER_PATTERN, $source, $matches);

        if (false === $count || 0 === $count) {
            return [];
        }

        /** @var list<string> $paths */
        $paths = array_values(array_unique($matches[1]));

        return $paths;
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            null === $value   => '',
            is_bool($value)   => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default           => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        };
    }
}
