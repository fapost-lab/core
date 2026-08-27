<?php

declare(strict_types=1);

namespace App\Domains\Flow\Nodes;

/**
 * Builder-only annotation node types — visual notes on the canvas with no
 * runtime behaviour and no NodeHandler. They live in the draft for the builder
 * to render, are skipped by validation, and are stripped from the published
 * definition so the engine never encounters them.
 */
final class AnnotationNodeTypes
{
    public const string COMMENT = 'comment';

    /**
     * @var list<string>
     */
    public const array TYPES = [self::COMMENT];

    public static function isAnnotation(mixed $type): bool
    {
        return is_string($type) && in_array($type, self::TYPES, true);
    }
}
