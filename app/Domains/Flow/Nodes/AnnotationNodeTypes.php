<?php

declare(strict_types=1);

namespace App\Domains\Flow\Nodes;

use Fapost\Support\Builder\Schema\Fields\TextareaField;
use Fapost\Support\Builder\Schema\Schema;

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
     * Config key holding the note body of a comment node.
     */
    public const string COMMENT_TEXT = 'text';

    /**
     * @var list<string>
     */
    public const array TYPES = [self::COMMENT];

    public static function isAnnotation(mixed $type): bool
    {
        return is_string($type) && in_array($type, self::TYPES, true);
    }

    /**
     * Builder config schema for the comment node. Annotation nodes have no
     * NodeHandler, so their schema lives here and is surfaced by
     * `NodeTypesController` alongside the registry entries.
     *
     * @return array<string, mixed>
     */
    public static function commentConfigSchema(): array
    {
        return Schema::make()
            ->fields([
                TextareaField::make(self::COMMENT_TEXT)
                    ->label('Note')
                    ->placeholder('Why this part of the flow looks the way it does…')
                    ->help('Visible in the builder only — comments are stripped from the published flow.')
                    ->withoutVariablePicker(),
            ])
            ->toArray();
    }
}
